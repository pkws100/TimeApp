#include <Arduino.h>
#include <ArduinoJson.h>
#include <DNSServer.h>
#include <HTTPClient.h>
#include <LittleFS.h>
#include <LiquidCrystal_I2C.h>
#include <MFRC522.h>
#include <Preferences.h>
#include <SPI.h>
#include <WebServer.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <Wire.h>
#include <mbedtls/base64.h>
#include <mbedtls/pk.h>
#include <mbedtls/sha256.h>
#include <time.h>
#include "TrustConfig.h"

static const char *FIRMWARE_VERSION = "pkws-time-terminal-v1.1.0";
static const char *NVS_NAMESPACE = "pkws-time";
static const char *SETUP_AP_PASSWORD = "change-me-setup";
static const char *PORTAL_ADMIN_PASSWORD = "change-me-portal";
static const char *TRUST_ACTIVE = "/trust-active.json";
static const char *TRUST_PREVIOUS = "/trust-previous.json";
static const char *TRUST_TEMP = "/trust-new.json";
static const char *TRUST_STAGING = "/trust-staging.json";
static const char *TRUST_OLD_PENDING = "/trust-old-pending.json";
static const char *OFFLINE_QUEUE = "/offline-scans.json";
static const char *OFFLINE_QUEUE_PREVIOUS = "/offline-scans-previous.json";
static const uint8_t LCD_ADDRESS = 0x27, LCD_COLS = 20, LCD_ROWS = 4;
static const uint8_t PIN_RC522_SS = 5, PIN_RC522_RST = 27, PIN_I2C_SDA = 21, PIN_I2C_SCL = 22;
static const uint8_t PIN_LED_GREEN = 25, PIN_LED_RED = 26, PIN_LED_YELLOW = 33, PIN_BUZZER = 32, PIN_SETUP_BUTTON = 13;
static const uint16_t HTTP_TIMEOUT_MS = 7000;
static const unsigned long WIFI_ATTEMPT_MS = 5000, API_RETRY_MS = 15000, TIME_SYNC_TIMEOUT_MS = 30000;
static const unsigned long TRUST_CHECK_INTERVAL_MS = 24UL * 60UL * 60UL * 1000UL;
static const size_t MAX_BUNDLE_BYTES = 24576, MAX_CERTIFICATES = 8, MAX_QUEUE_ENTRIES = 64;

enum class ApiTransport { HTTP_PLAIN, HTTPS_VERIFIED, INVALID };
enum class TrustState { NOT_APPLICABLE, CURRENT, WARNING, REPLACE_REQUIRED, RECOVERY, INVALID };
enum class DeviceState { BOOT, CONFIG_CHECK, SETUP_MODE, WIFI_CONNECT, TIME_SYNC, API_CONFIG, TLS_RECOVERY, READY, SEND_SCAN, SHOW_RESULT, ERROR_RETRY };

struct TerminalConfig { String ssid, wifiPassword, apiBaseUrl, terminalId, terminalToken, deviceName; };
struct TrustBundle { uint32_t version = 0; String warningAfter, replaceBefore, pem; bool valid = false; };
struct OfflineScan { String requestId, uid, deviceTime, reason; };

String factoryPem();
size_t queueDepth();

LiquidCrystal_I2C lcd(LCD_ADDRESS, LCD_COLS, LCD_ROWS);
MFRC522 rfid(PIN_RC522_SS, PIN_RC522_RST);
Preferences preferences;
WebServer server(80);
DNSServer dns;
TerminalConfig config;
TrustBundle trust;
DeviceState state = DeviceState::BOOT;
TrustState trustState = TrustState::INVALID;
String apiStatus = "not_checked", lastError, portalSession, formKey, currentUid, currentRequestId;
String uploadedBundle;
String recoveryStatus = "none";
bool lastFailureIsTls = false;
unsigned long setupButtonDownAt = 0;
unsigned long stateAt = 0, wifiAttemptAt = 0, timeSyncAt = 0, nextApiRetryAt = 0, resultUntil = 0, lastUidAt = 0, lastTrustCheckAt = 0;
uint8_t wifiAttempts = 0, scanAttempts = 0;
bool portalStarted = false, setupAp = false, restartScheduled = false, queueOverflow = false;

String trimSlash(String value) { value.trim(); while (value.endsWith("/")) value.remove(value.length() - 1); return value; }
String fit(String value) { value.replace("\n", " "); value.replace("\r", " "); value.trim(); return value.length() > LCD_COLS ? value.substring(0, LCD_COLS) : value; }
void show(const String &a, const String &b = "", const String &c = "", const String &d = "") { String lines[] = {a,b,c,d}; for (uint8_t i=0;i<4;i++) { lcd.setCursor(0,i); String line=fit(lines[i]); while(line.length()<LCD_COLS) line += ' '; lcd.print(line); } }
void leds(bool red, bool yellow, bool green) { digitalWrite(PIN_LED_RED,red); digitalWrite(PIN_LED_YELLOW,yellow); digitalWrite(PIN_LED_GREEN,green); }
void beep(uint16_t ms = 80) { digitalWrite(PIN_BUZZER,HIGH); delay(ms); digitalWrite(PIN_BUZZER,LOW); }
void enter(DeviceState next) { state=next; stateAt=millis(); if(next==DeviceState::TIME_SYNC) timeSyncAt=millis(); if(next==DeviceState::ERROR_RETRY) nextApiRetryAt=millis()+API_RETRY_MS; }

ApiTransport transportFor(const String &base) {
  String v=base; v.toLowerCase();
  if(v.startsWith("http://")) return ApiTransport::HTTP_PLAIN;
  if(v.startsWith("https://")) return ApiTransport::HTTPS_VERIFIED;
  return ApiTransport::INVALID;
}
String transportLabel() { ApiTransport t=transportFor(config.apiBaseUrl); return t==ApiTransport::HTTP_PLAIN ? "http" : t==ApiTransport::HTTPS_VERIFIED ? "https" : "invalid"; }
String trustLabel() { switch(trustState) { case TrustState::NOT_APPLICABLE:return "not-applicable"; case TrustState::CURRENT:return "current"; case TrustState::WARNING:return "warning"; case TrustState::REPLACE_REQUIRED:return "replace-required"; case TrustState::RECOVERY:return "recovery"; default:return "invalid"; } }
bool plausibleTime() { return time(nullptr) >= 1704067200; }
String nowIso() { if(!plausibleTime()) return ""; struct tm t; time_t n=time(nullptr); gmtime_r(&n,&t); char out[25]; strftime(out,sizeof(out),"%Y-%m-%dT%H:%M:%SZ",&t); return String(out); }

bool loadConfig() {
  preferences.begin(NVS_NAMESPACE,true);
  config.ssid=preferences.getString("ssid",""); config.wifiPassword=preferences.getString("wifi_password","");
  config.apiBaseUrl=trimSlash(preferences.getString("api_base_url", "")); config.terminalId=preferences.getString("terminal_id","");
  config.terminalToken=preferences.getString("terminal_token",""); config.deviceName=preferences.getString("device_name",""); preferences.end();
  return config.ssid.length() && config.apiBaseUrl.length() && config.terminalId.length() && config.terminalToken.length() && transportFor(config.apiBaseUrl)!=ApiTransport::INVALID;
}
void saveConfig() {
  String p=server.arg("wifi_password"), token=server.arg("terminal_token");
  if(!p.length()) p=config.wifiPassword; if(!token.length()) token=config.terminalToken;
  preferences.begin(NVS_NAMESPACE,false); preferences.putString("ssid",server.arg("ssid")); preferences.putString("wifi_password",p);
  preferences.putString("api_base_url",trimSlash(server.arg("api_base_url"))); preferences.putString("terminal_id",server.arg("terminal_id")); preferences.putString("terminal_token",token); preferences.putString("device_name",server.arg("device_name")); preferences.end();
}

String canonicalPayload(JsonObjectConst payload) {
  String out="{\"format_version\":" + String((int)(payload["format_version"]|0));
  out += ",\"bundle_version\":" + String((uint32_t)(payload["bundle_version"]|0));
  out += ",\"created_at\":\"" + String((const char*)(payload["created_at"]|"")) + "\"";
  out += ",\"warning_after\":\"" + String((const char*)(payload["warning_after"]|"")) + "\"";
  out += ",\"replace_before\":\"" + String((const char*)(payload["replace_before"]|"")) + "\",\"certificates\":[";
  JsonArrayConst certs=payload["certificates"].as<JsonArrayConst>(); for(size_t i=0;i<certs.size();i++){ if(i)out+=','; String cert=certs[i].as<String>(); cert.replace("\\","\\\\"); cert.replace("\n","\\n"); cert.replace("\"","\\\""); out+='\"'+cert+'\"'; } return out+"]}";
}
bool verifySignature(JsonObjectConst payload, const char *signature) {
  String canonical=canonicalPayload(payload); uint8_t hash[32]; mbedtls_sha256_ret((const unsigned char*)canonical.c_str(),canonical.length(),hash,0);
  size_t signatureLength=0; if(mbedtls_base64_decode(nullptr,0,&signatureLength,(const unsigned char*)signature,strlen(signature))!=MBEDTLS_ERR_BASE64_BUFFER_TOO_SMALL || signatureLength>128) return false;
  uint8_t decoded[128]; if(mbedtls_base64_decode(decoded,sizeof(decoded),&signatureLength,(const unsigned char*)signature,strlen(signature))!=0) return false;
  mbedtls_pk_context key; mbedtls_pk_init(&key); int parsed=mbedtls_pk_parse_public_key(&key,(const unsigned char*)TRUST_SIGNING_PUBLIC_KEY,strlen(TRUST_SIGNING_PUBLIC_KEY)+1);
  int result=parsed==0 ? mbedtls_pk_verify(&key,MBEDTLS_MD_SHA256,hash,sizeof(hash),decoded,signatureLength) : -1; mbedtls_pk_free(&key); return result==0;
}
bool parseBundle(const String &raw, TrustBundle &out, String &why) {
  if(!raw.length() || raw.length()>MAX_BUNDLE_BYTES) { why="Bundle-Groesse ungueltig"; return false; }
  DynamicJsonDocument doc(MAX_BUNDLE_BYTES); if(deserializeJson(doc,raw)) { why="Bundle JSON ungueltig"; return false; }
  JsonObjectConst root=doc.as<JsonObjectConst>(); JsonObjectConst payload=root["payload"].as<JsonObjectConst>(); const char *algorithm=root["signature_algorithm"]|""; const char *signature=root["signature"]|"";
  if(payload.isNull() || String(algorithm)!="ECDSA-P256-SHA256" || !strlen(signature) || (int)(payload["format_version"]|0)!=1) { why="Bundle-Format ungueltig"; return false; }
  JsonArrayConst certs=payload["certificates"].as<JsonArrayConst>(); if(certs.isNull() || !certs.size() || certs.size()>MAX_CERTIFICATES) { why="Keine CA-Zertifikate"; return false; }
  String pem; for(JsonVariantConst cert:certs) { String one=cert.as<String>(); if(!one.startsWith("-----BEGIN CERTIFICATE-----") || !one.endsWith("-----END CERTIFICATE-----") || one.length()>8192) { why="CA-Zertifikat ungueltig"; return false; } pem+=one+"\n"; }
  if(!verifySignature(payload,signature)) { why="Bundle-Signatur ungueltig"; return false; }
  out.version=(uint32_t)(payload["bundle_version"]|0); out.warningAfter=String((const char*)(payload["warning_after"]|"")); out.replaceBefore=String((const char*)(payload["replace_before"]|"")); out.pem=pem; out.valid=out.version>0; if(!out.valid) why="Bundle-Version ungueltig"; return out.valid;
}
bool readBundle(const char *path, TrustBundle &out, String &why) { if(!LittleFS.exists(path)) return false; File f=LittleFS.open(path,"r"); if(!f) return false; String raw=f.readString(); f.close(); return parseBundle(raw,out,why); }
bool parseTrustDate(const String &value, time_t &out) { struct tm tm={}; if(!value.length() || !strptime(value.c_str(),"%Y-%m-%dT%H:%M:%SZ",&tm)) return false; out=mktime(&tm); return out>0; }
void refreshTrustState() {
  if(transportFor(config.apiBaseUrl)==ApiTransport::HTTP_PLAIN) { trustState=TrustState::NOT_APPLICABLE; return; }
  if(!trust.valid) { trustState=TrustState::INVALID; return; }
  time_t replace=0, warning=0; if(!plausibleTime() || !parseTrustDate(trust.replaceBefore,replace) || !parseTrustDate(trust.warningAfter,warning)) { trustState=TrustState::INVALID; return; }
  time_t current=time(nullptr); trustState=current>=replace?TrustState::REPLACE_REQUIRED:(current>=warning || (replace-current)<=365*86400)?TrustState::WARNING:TrustState::CURRENT;
}
void loadTrust() { String why; TrustBundle active; if(readBundle(TRUST_ACTIVE,active,why)) trust=active; else { trust=TrustBundle(); trust.version=0; trust.pem=factoryPem(); trust.valid=trust.pem.length()>0; } refreshTrustState(); }
bool atomicWrite(const char *path,const String &content) { File f=LittleFS.open(TRUST_STAGING,"w"); if(!f) return false; bool ok=f.print(content)==content.length(); f.flush(); f.close(); if(!ok) { LittleFS.remove(TRUST_STAGING); return false; } LittleFS.remove(path); return LittleFS.rename(TRUST_STAGING,path); }
bool installBundle(const String &raw, bool permitFactoryRollback, String &why) {
  TrustBundle candidate; if(!parseBundle(raw,candidate,why)) return false; if(!permitFactoryRollback && trust.valid && candidate.version<=trust.version) { why="Anti-Rollback: alte Version"; return false; }
  if(!atomicWrite(TRUST_TEMP,raw)) { why="Bundle temporär nicht speicherbar"; return false; }
  LittleFS.remove(TRUST_OLD_PENDING); if(LittleFS.exists(TRUST_ACTIVE) && !LittleFS.rename(TRUST_ACTIVE,TRUST_OLD_PENDING)) { why="Aktives Bundle nicht sicherbar"; return false; }
  if(!LittleFS.rename(TRUST_TEMP,TRUST_ACTIVE)) { if(LittleFS.exists(TRUST_OLD_PENDING)) LittleFS.rename(TRUST_OLD_PENDING,TRUST_ACTIVE); why="Bundle nicht aktivierbar"; return false; }
  if(LittleFS.exists(TRUST_OLD_PENDING)) { LittleFS.remove(TRUST_PREVIOUS); LittleFS.rename(TRUST_OLD_PENDING,TRUST_PREVIOUS); }
  trust=candidate; refreshTrustState(); return true;
}
bool restorePrevious() { if(!LittleFS.exists(TRUST_PREVIOUS)) return false; String why; TrustBundle previous; if(!readBundle(TRUST_PREVIOUS,previous,why)) return false; LittleFS.remove(TRUST_ACTIVE); if(!LittleFS.rename(TRUST_PREVIOUS,TRUST_ACTIVE)) return false; trust=previous; refreshTrustState(); return true; }
bool restoreFactory() { LittleFS.remove(TRUST_ACTIVE); trust=TrustBundle(); trust.version=0; trust.pem=factoryPem(); trust.valid=trust.pem.length()>0; refreshTrustState(); return trust.valid; }

void addHeaders(HTTPClient &http) { http.addHeader("X-Terminal-ID",config.terminalId); http.addHeader("Authorization","Bearer "+config.terminalToken); http.addHeader("X-Terminal-Firmware",FIRMWARE_VERSION); http.addHeader("X-Terminal-Transport",transportLabel()); http.addHeader("X-Terminal-TLS-State",transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED?"verified":"not-applicable"); http.addHeader("X-Terminal-Trust-Version",String(trust.version)); http.addHeader("X-Terminal-Trust-State",trustLabel()); http.addHeader("X-Terminal-Queue-Depth",String(queueDepth())); http.addHeader("X-Terminal-Recovery-Status",recoveryStatus); }
bool beginApi(HTTPClient &http, WiFiClient &plain, WiFiClientSecure &secure, const String &url, String &why) {
  ApiTransport type=transportFor(config.apiBaseUrl); http.setTimeout(HTTP_TIMEOUT_MS);
  if(type==ApiTransport::HTTP_PLAIN) { trustState=TrustState::NOT_APPLICABLE; return http.begin(plain,url); }
  if(type!=ApiTransport::HTTPS_VERIFIED) { why="API-URL muss http:// oder https:// sein"; return false; }
  if(!plausibleTime()) { why="Systemzeit nicht synchronisiert"; return false; }
  if(!trust.valid) { trustState=TrustState::INVALID; why="Kein CA-Trust vorhanden"; return false; }
  secure.setCACert(trust.pem.c_str());
  return http.begin(secure,url);
}
bool apiGet(const String &path, String &body, int &status, String &why, bool authenticated=true) {
  lastFailureIsTls=false; WiFiClient plain; WiFiClientSecure secure; HTTPClient http; String url=trimSlash(config.apiBaseUrl)+path; if(!beginApi(http,plain,secure,url,why)) return false; if(authenticated) addHeaders(http); status=http.GET(); body=status>0?http.getString():""; if(status<=0) { char ssl[96]={0}; lastFailureIsTls=transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED && secure.lastError(ssl,sizeof(ssl))!=0; why="Verbindung fehlgeschlagen ("+String(status)+")"; } http.end(); return status>0;
}
bool apiPostScan(const OfflineScan &scan, String &body, int &status, String &why) {
  lastFailureIsTls=false; WiFiClient plain; WiFiClientSecure secure; HTTPClient http; if(!beginApi(http,plain,secure,trimSlash(config.apiBaseUrl)+"/api/v1/terminal/scan",why)) return false; addHeaders(http); http.addHeader("Content-Type","application/json"); DynamicJsonDocument doc(512); doc["request_id"]=scan.requestId; doc["nfc_uid"]=scan.uid; if(scan.deviceTime.length()) doc["device_time"]=scan.deviceTime; else doc["device_time"]=nullptr; doc["firmware_version"]=FIRMWARE_VERSION; String json; serializeJson(doc,json); status=http.POST(json); body=status>0?http.getString():""; if(status<=0) { char ssl[96]={0}; lastFailureIsTls=transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED && secure.lastError(ssl,sizeof(ssl))!=0; why="Scan-Verbindung fehlgeschlagen ("+String(status)+")"; } http.end(); return status>0;
}
bool recoveryDownload(String &bundle, String &why) {
  /* The only insecure request in this firmware: fixed same-origin public path, GET, no terminal headers, no body. */
  if(transportFor(config.apiBaseUrl)!=ApiTransport::HTTPS_VERIFIED) return false;
  WiFiClientSecure recovery; recovery.setInsecure(); HTTPClient http; String url=trimSlash(config.apiBaseUrl)+"/api/v1/terminal/trust-bundle"; http.setTimeout(HTTP_TIMEOUT_MS);
  if(!http.begin(recovery,url)) { why="Recovery-Verbindung nicht startbar"; return false; } int status=http.GET(); bundle=status==200?http.getString():""; http.end(); if(status!=200) { why="Recovery-Bundle nicht verfügbar"; return false; } return true;
}
bool verifiedBundleDownload(String &bundle, String &why) {
  int status=0;
  if(!apiGet("/api/v1/terminal/trust-bundle",bundle,status,why,false)) return false;
  if(status!=200) { why="Trust-Bundle HTTP "+String(status); return false; }
  return true;
}
bool verifyActiveTrust(String &why) { String body; int status=0; return apiGet("/api/v1/terminal/trust-bundle",body,status,why,false) && status==200; }

bool readQueue(OfflineScan entries[], size_t &count) { count=0; if(!LittleFS.exists(OFFLINE_QUEUE)) return true; File f=LittleFS.open(OFFLINE_QUEUE,"r"); if(!f) return false; DynamicJsonDocument doc(12288); if(deserializeJson(doc,f)) { f.close(); return false; } f.close(); JsonArray a=doc.as<JsonArray>(); for(JsonVariant v:a) { if(count>=MAX_QUEUE_ENTRIES) break; entries[count++]={String((const char*)(v["request_id"]|"")),String((const char*)(v["nfc_uid"]|"")),String((const char*)(v["device_time"]|"")),String((const char*)(v["queued_reason"]|""))}; } return true; }
bool writeQueue(OfflineScan entries[], size_t count) { DynamicJsonDocument doc(12288); JsonArray a=doc.to<JsonArray>(); for(size_t i=0;i<count;i++){ JsonObject x=a.createNestedObject(); x["request_id"]=entries[i].requestId; x["nfc_uid"]=entries[i].uid; x["device_time"]=entries[i].deviceTime; x["firmware_version"]=FIRMWARE_VERSION; x["queued_reason"]=entries[i].reason; } String json; serializeJson(doc,json); File f=LittleFS.open("/queue-new.json","w"); if(!f) return false; bool ok=f.print(json)==json.length(); f.flush(); f.close(); if(!ok)return false; LittleFS.remove(OFFLINE_QUEUE_PREVIOUS); if(LittleFS.exists(OFFLINE_QUEUE) && !LittleFS.rename(OFFLINE_QUEUE,OFFLINE_QUEUE_PREVIOUS)) return false; if(!LittleFS.rename("/queue-new.json",OFFLINE_QUEUE)){ if(LittleFS.exists(OFFLINE_QUEUE_PREVIOUS)) LittleFS.rename(OFFLINE_QUEUE_PREVIOUS,OFFLINE_QUEUE); return false; } return true; }
size_t queueDepth() { OfflineScan e[MAX_QUEUE_ENTRIES]; size_t c=0; readQueue(e,c); return c; }
bool enqueue(const OfflineScan &scan) { OfflineScan e[MAX_QUEUE_ENTRIES]; size_t c=0; if(!readQueue(e,c)) return false; if(c>=MAX_QUEUE_ENTRIES) { queueOverflow=true; return false; } e[c++]=scan; return writeQueue(e,c); }
void syncQueue() { if(!WiFi.isConnected()) return; OfflineScan e[MAX_QUEUE_ENTRIES]; size_t c=0; if(!readQueue(e,c)) return; size_t done=0; for(;done<c;done++){ String body,why; int status=0; if(!apiPostScan(e[done],body,status,why) || status<200 || status>=300) break; } if(done){ for(size_t i=done;i<c;i++) e[i-done]=e[i]; writeQueue(e,c-done); } }

String escapeHtml(String v){ v.replace("&","&amp;");v.replace("<","&lt;");v.replace(">","&gt;");v.replace("\"","&quot;");return v; }
bool loggedIn(){ return portalSession.length() && server.header("Cookie").indexOf("pkws_portal="+portalSession)>=0; }
bool authorizedPost(){ return loggedIn() && server.arg("setup_key")==formKey; }
String portalPage() { String mode=transportLabel(); String page="<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><style>body{font:16px Arial;margin:1rem;background:#f4f7f8}main{max-width:680px;margin:auto}.p{background:white;padding:1rem;margin:.8rem 0;border-radius:8px}input,button{box-sizing:border-box;width:100%;padding:.7rem;margin:.25rem 0}pre{white-space:pre-wrap}small{color:#536}.warn{color:#8a6200}</style><main><div class=p><h1>PK-WS TimeApp</h1><p>Firmware "+String(FIRMWARE_VERSION)+" · WLAN "+escapeHtml(WiFi.isConnected()?WiFi.SSID():"nicht verbunden")+"</p><pre>Transport: "+mode+"\nNTP: "+String(plausibleTime()?nowIso():"nicht synchronisiert")+"\nTLS: "+trustLabel()+"\nTrust-Bundle: v"+String(trust.version)+"\nWarnung ab: "+escapeHtml(trust.warningAfter)+"\nAustausch bis: "+escapeHtml(trust.replaceBefore)+"\nOffline-Queue: "+String(queueDepth())+"/"+String(MAX_QUEUE_ENTRIES)+"\nLetzter Fehler: "+escapeHtml(lastError)+"</pre><p class=warn>"+(mode=="http"?"Unverschlüsselte HTTP-Verbindung – nur für geschützte interne Netze.":mode=="https"?"Verschlüsselte und geprüfte HTTPS-Verbindung.":"Ungültiges URL-Schema.")+"</p></div><div class=p><form method=post action=/save><input type=hidden name=setup_key value='"+formKey+"'><label>WLAN-SSID</label><input name=ssid value='"+escapeHtml(config.ssid)+"'><label>WLAN-Passwort (leer beibehalten)</label><input type=password name=wifi_password><label>API Base URL</label><input name=api_base_url value='"+escapeHtml(config.apiBaseUrl)+"' placeholder='https://terminal-api.pk-ws.de'><label>Terminal-ID</label><input name=terminal_id value='"+escapeHtml(config.terminalId)+"'><label>Terminal-Token (leer beibehalten)</label><input type=password name=terminal_token><button>Speichern und neu starten</button></form><form method=post action=/test><input type=hidden name=setup_key value='"+formKey+"'><button>API testen</button></form></div><div class=p><h2>Trust-Bundle</h2><form method=post action=/trust/check><input type=hidden name=setup_key value='"+formKey+"'><button>Update prüfen</button></form><form method=post action=/trust/previous><input type=hidden name=setup_key value='"+formKey+"'><button>Vorheriges Bundle aktivieren</button></form><form method=post action=/trust/factory><input type=hidden name=setup_key value='"+formKey+"'><button>Factory-Bundle aktivieren</button></form><form method=post action=/trust/upload enctype='multipart/form-data'><input type=hidden name=setup_key value='"+formKey+"'><input type=file name=bundle accept='application/json'><button>Signiertes Bundle prüfen/installieren</button></form></div></main>"; return page; }
void redirectPortal(const String &message){ server.sendHeader("Location","/?message="+message); server.send(303); }
void startPortal(){ if(portalStarted)return; server.collectHeaders(new const char*[1]{"Cookie"},1); formKey=String((uint32_t)esp_random(),HEX)+String((uint32_t)esp_random(),HEX); portalSession=String((uint32_t)esp_random(),HEX)+String((uint32_t)esp_random(),HEX);
  server.on("/",HTTP_GET,[](){ if(!loggedIn()){server.send(200,"text/html","<form method=post action=/login><input name=password type=password placeholder='Portal-Passwort'><button>Login</button></form>");return;} server.send(200,"text/html",portalPage()); });
  server.on("/login",HTTP_POST,[](){ if(server.arg("password")==PORTAL_ADMIN_PASSWORD){server.sendHeader("Set-Cookie","pkws_portal="+portalSession+"; HttpOnly; SameSite=Strict"); redirectPortal("login");}else server.send(403,"text/plain","Login fehlgeschlagen"); });
  server.on("/save",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} String base=trimSlash(server.arg("api_base_url")); if(transportFor(base)==ApiTransport::INVALID){server.send(422,"text/plain","API-URL muss mit http:// oder https:// beginnen");return;} saveConfig(); redirectPortal("saved"); restartScheduled=true;});
  server.on("/test",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} String b,w;int s=0; if(transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED&&!plausibleTime()){server.send(409,"text/plain","HTTPS benötigt eine synchronisierte Systemzeit.");return;} bool ok=apiGet("/api/v1/terminal/config",b,s,w); server.send(ok&&s>=200&&s<300?200:502,"application/json",ok?b:String("{\"ok\":false,\"message\":\"")+w+"\"}");});
  server.on("/trust/check",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} String raw,w; if(!verifiedBundleDownload(raw,w)||!installBundle(raw,false,w)||!verifyActiveTrust(w)){restorePrevious();server.send(422,"text/plain",w);return;} redirectPortal("trust-updated");});
  server.on("/trust/previous",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} bool ok=restorePrevious(); server.send(ok?200:422,"text/plain",ok?"Vorheriges Bundle aktiv.":"Kein gültiges vorheriges Bundle.");});
  server.on("/trust/factory",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} server.send(restoreFactory()?200:422,"text/plain",trust.valid?"Factory-Bundle aktiv.":"Factory-Trust ist nicht provisioniert.");});
  server.on("/trust/upload",HTTP_POST,[](){if(!authorizedPost()){server.send(403);return;} String why; bool ok=uploadedBundle.length()<=MAX_BUNDLE_BYTES && installBundle(uploadedBundle,false,why) && verifyActiveTrust(why); if(!ok)restorePrevious(); server.send(ok?200:422,"text/plain",ok?"Bundle installiert":(why.length()?why:"Bundle-Groesse ungueltig"));},[](){HTTPUpload &up=server.upload(); if(up.status==UPLOAD_FILE_START) uploadedBundle=""; else if(up.status==UPLOAD_FILE_WRITE && uploadedBundle.length()+up.currentSize<=MAX_BUNDLE_BYTES) uploadedBundle+=(const char*)up.buf;});
  server.begin(); portalStarted=true;
}
void startSetupAp(){ if(setupAp)return; WiFi.mode(WIFI_AP_STA); String ssid="PKWS-TimeApp-Setup-"+String((uint32_t)ESP.getEfuseMac(),HEX).substring(4); WiFi.softAP(ssid.c_str(),SETUP_AP_PASSWORD); dns.start(53,"*",WiFi.softAPIP()); setupAp=true; startPortal(); }

void configureNtp(){ configTime(0,0,"pool.ntp.org","time.nist.gov"); }
void connectWifi(){ if(!wifiAttemptAt){WiFi.mode(WIFI_STA);WiFi.begin(config.ssid.c_str(),config.wifiPassword.c_str());wifiAttemptAt=millis();show("WLAN verbinden",config.ssid,"bitte warten");} if(WiFi.isConnected()){wifiAttemptAt=0;wifiAttempts=0;startPortal();configureNtp(); enter(transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED?DeviceState::TIME_SYNC:DeviceState::API_CONFIG);return;} if(millis()-wifiAttemptAt>WIFI_ATTEMPT_MS){wifiAttemptAt=0;if(++wifiAttempts>=4){lastError="WLAN nicht erreichbar";startSetupAp();enter(DeviceState::SETUP_MODE);}} }
bool fetchConfig(){ String body,why;int status=0; if(!apiGet("/api/v1/terminal/config",body,status,why)){lastError=why;return false;} if(status<200||status>=300){lastError="API config HTTP "+String(status);return false;} DynamicJsonDocument doc(4096);if(deserializeJson(doc,body)){lastError="API config JSON ungueltig";return false;} JsonObject trustMeta=doc["trust_bundle"].as<JsonObject>(); if(!trustMeta.isNull() && (uint32_t)(trustMeta["latest_version"]|0)>trust.version && (lastTrustCheckAt==0 || millis()-lastTrustCheckAt>TRUST_CHECK_INTERVAL_MS)){lastTrustCheckAt=millis();String raw,w;if(verifiedBundleDownload(raw,w)){String installWhy; if(!installBundle(raw,false,installWhy) || !verifyActiveTrust(installWhy)){restorePrevious();lastError=installWhy;}}else lastError=w;} apiStatus="config_ok";syncQueue();return true; }
bool tlsRecover(){ trustState=TrustState::RECOVERY; recoveryStatus="running"; show("TLS Wiederherstellung","Trust-Bundle","wird geprüft"); String raw,why; if(!recoveryDownload(raw,why)||!installBundle(raw,false,why)||!verifyActiveTrust(why)){restorePrevious();lastError=why;recoveryStatus="failed";trustState=TrustState::INVALID;return false;} bool ok=fetchConfig(); recoveryStatus=ok?"recovered":"verify_failed"; if(!ok)restorePrevious(); return ok; }
String newRequestId(){ return "pkws-"+String((uint32_t)esp_random(),HEX)+"-"+String(millis(),HEX); }
void queueCurrent(const String &reason){ OfflineScan scan={currentRequestId,currentUid,nowIso(),reason}; if(enqueue(scan)){show(reason=="tls_validation_failed"?"Server nicht sicher":"Server nicht erreichbar","Scan gespeichert","Wird nachgereicht","Admin informieren");leds(true,true,false);beep(140);}else{show("Offline-Queue voll","Scan nicht speicherbar","Admin informieren");leds(true,false,false);beep(250);} resultUntil=millis()+8000;enter(DeviceState::SHOW_RESULT); }
void sendCurrent(){ OfflineScan scan={currentRequestId,currentUid,nowIso(),""};String body,why;int status=0; if(apiPostScan(scan,body,status,why)&&status>=200&&status<300){ DynamicJsonDocument d(2048);if(!deserializeJson(d,body)){JsonArray lines=d["display"]["lines"].as<JsonArray>();show(String((const char*)(lines[0]|"OK")),String((const char*)(lines[1]|"")),String((const char*)(lines[2]|"")),String((const char*)(lines[3]|"")));}syncQueue();leds(false,false,true);beep();resultUntil=millis()+8000;enter(DeviceState::SHOW_RESULT);return;} lastError=why.length()?why:"API HTTP "+String(status); if(lastFailureIsTls){enter(DeviceState::TLS_RECOVERY);return;} if(status>0){show("API Fehler",String(status),"Scan abgelehnt");resultUntil=millis()+8000;enter(DeviceState::SHOW_RESULT);return;} if(++scanAttempts<3)return;queueCurrent("network_failed"); }
void scanNfc(){ if(!rfid.PICC_IsNewCardPresent()||!rfid.PICC_ReadCardSerial())return; String uid;for(byte i=0;i<rfid.uid.size;i++){if(i)uid+=':';if(rfid.uid.uidByte[i]<16)uid+='0';uid+=String(rfid.uid.uidByte[i],HEX);}uid.toUpperCase();rfid.PICC_HaltA();rfid.PCD_StopCrypto1();if(uid==currentUid&&millis()-lastUidAt<2000)return;currentUid=uid;lastUidAt=millis();currentRequestId=newRequestId();scanAttempts=0;show("Tag erkannt","Anfrage läuft");leds(false,true,false);enter(DeviceState::SEND_SCAN); }

void setup(){Serial.begin(115200);setenv("TZ","UTC0",1);tzset();pinMode(PIN_LED_GREEN,OUTPUT);pinMode(PIN_LED_RED,OUTPUT);pinMode(PIN_LED_YELLOW,OUTPUT);pinMode(PIN_BUZZER,OUTPUT);pinMode(PIN_SETUP_BUTTON,INPUT_PULLUP);Wire.begin(PIN_I2C_SDA,PIN_I2C_SCL);lcd.init();lcd.backlight();SPI.begin();rfid.PCD_Init();LittleFS.begin(true);show("PK-WS TimeApp","Terminal startet",FIRMWARE_VERSION);beep();}
void loop(){if(portalStarted)server.handleClient();if(setupAp)dns.processNextRequest();if(digitalRead(PIN_SETUP_BUTTON)==LOW){if(!setupButtonDownAt)setupButtonDownAt=millis();if(millis()-setupButtonDownAt>=5000){startSetupAp();enter(DeviceState::SETUP_MODE);setupButtonDownAt=0;}}else setupButtonDownAt=0;if(restartScheduled&&millis()-stateAt>1200)ESP.restart();if(state!=DeviceState::SETUP_MODE&&state!=DeviceState::WIFI_CONNECT&&WiFi.isConnected()==false){wifiAttemptAt=0;enter(DeviceState::WIFI_CONNECT);}switch(state){case DeviceState::BOOT:if(millis()-stateAt>1000)enter(DeviceState::CONFIG_CHECK);break;case DeviceState::CONFIG_CHECK:if(loadConfig()){loadTrust();enter(DeviceState::WIFI_CONNECT);}else{startSetupAp();enter(DeviceState::SETUP_MODE);}break;case DeviceState::SETUP_MODE:break;case DeviceState::WIFI_CONNECT:connectWifi();break;case DeviceState::TIME_SYNC:if(plausibleTime()){enter(DeviceState::API_CONFIG);break;}show("Zeit synchronisieren","HTTPS benötigt NTP",String((TIME_SYNC_TIMEOUT_MS-(millis()-timeSyncAt))/1000)+" Sekunden");if(millis()-timeSyncAt>TIME_SYNC_TIMEOUT_MS){lastError="NTP-Timeout: HTTPS blockiert";enter(DeviceState::ERROR_RETRY);}break;case DeviceState::API_CONFIG:if(fetchConfig())enter(DeviceState::READY);else if(lastFailureIsTls)enter(DeviceState::TLS_RECOVERY);else enter(DeviceState::ERROR_RETRY);break;case DeviceState::TLS_RECOVERY:if(tlsRecover())enter(DeviceState::READY);else if(currentRequestId.length()&&currentUid.length())queueCurrent("tls_validation_failed");else enter(DeviceState::ERROR_RETRY);break;case DeviceState::READY:show("PK-WS TimeApp","Tag vorhalten","Bereit",(trustState==TrustState::WARNING||trustState==TrustState::REPLACE_REQUIRED)?"TLS-Wartung":"");leds(false,trustState==TrustState::WARNING||trustState==TrustState::REPLACE_REQUIRED,true);enter(DeviceState::SHOW_RESULT);resultUntil=millis()+100;break;case DeviceState::SEND_SCAN:sendCurrent();break;case DeviceState::SHOW_RESULT:scanNfc();break;case DeviceState::ERROR_RETRY:if(millis()>=nextApiRetryAt)enter(transportFor(config.apiBaseUrl)==ApiTransport::HTTPS_VERIFIED?DeviceState::TIME_SYNC:DeviceState::API_CONFIG);break;}}
String factoryPem() { return String(FACTORY_CA_ANCHOR_1) + "\n" + String(FACTORY_CA_ANCHOR_2); }
