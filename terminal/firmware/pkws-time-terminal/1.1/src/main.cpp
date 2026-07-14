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
#include <mbedtls/x509_crt.h>
#include <time.h>

#if __has_include("TrustConfig.local.h")
#include "TrustConfig.local.h"
#else
#error "TrustConfig.local.h fehlt. TrustConfig.example.h kopieren und den echten PK-WS P-256-Pruefschluessel eintragen."
#endif
#include "FactoryTrust.h"

static const char *FIRMWARE_VERSION = "pkws-time-terminal-v1.1.1";
static const char *NVS_NAMESPACE = "pkws-time";
static const char *SETUP_AP_PASSWORD = "change-me-setup";
static const char *PORTAL_ADMIN_PASSWORD = "change-me-portal";
static const uint8_t LCD_ADDRESS = 0x27;
static const uint8_t LCD_COLS = 20;
static const uint8_t LCD_ROWS = 4;

static const uint8_t PIN_RC522_SS = 5;
static const uint8_t PIN_RC522_RST = 27;
static const uint8_t PIN_I2C_SDA = 21;
static const uint8_t PIN_I2C_SCL = 22;
static const uint8_t PIN_LED_GREEN = 25;
static const uint8_t PIN_LED_RED = 26;
static const uint8_t PIN_LED_YELLOW = 33;
static const uint8_t PIN_BUZZER = 32;
static const uint8_t PIN_SETUP_BUTTON = 13;

static const unsigned long SETUP_BUTTON_HOLD_MS = 5000;
static const unsigned long DUPLICATE_UID_WINDOW_MS = 2000;
static const unsigned long WIFI_ATTEMPT_MS = 5000;
static const uint8_t WIFI_MAX_ATTEMPTS = 4;
static const unsigned long API_RETRY_MS = 15000;
static const uint16_t HTTP_TIMEOUT_MS = 5000;
static const unsigned long TIME_SYNC_TIMEOUT_MS = 30000;
static const unsigned long TRUST_WARNING_BUFFER_SECONDS = 90UL * 24UL * 60UL * 60UL;
static const size_t MAX_TRUST_BUNDLE_BYTES = 24576;
static const size_t MAX_TRUST_CERTIFICATES = 8;
static const size_t MAX_QUEUE_ENTRIES = 64;
static const char *TRUST_ACTIVE = "/trust-active.json";
static const char *TRUST_PREVIOUS = "/trust-previous.json";
static const char *TRUST_STAGING = "/trust-staging.json";
static const char *TRUST_NEW = "/trust-new.json";
static const char *TRUST_OLD_PENDING = "/trust-old-pending.json";
static const char *TRUST_RECOVERY_MARKER = "/trust-recovery.marker";
static const char *QUEUE_DIRECTORY = "/queue";
static const char *QUEUE_SEQUENCE_FILE = "/queue/sequence";

enum class DeviceState {
    BOOT,
    CONFIG_CHECK,
    SETUP_MODE,
    WIFI_CONNECT,
    TIME_SYNC,
    API_CONFIG,
    READY,
    NFC_SCAN,
    SEND_SCAN,
    TLS_RECOVERY,
    QUEUE_SYNC,
    SHOW_RESULT,
    ERROR_RETRY
};

enum class ApiTransport { HTTP_PLAIN, HTTPS_VERIFIED, INVALID };
enum class TlsState { NOT_APPLICABLE, NOT_CHECKED, TIME_INVALID, TRUST_MISSING, CONNECTING, VERIFIED, VALIDATION_FAILED, RECOVERY };
enum class ScanLifecycle { NONE, VOLATILE, PERSISTED, SENT_CONFIRMED, REJECTED };
enum class RetryClass { TEMPORARY, PERMANENT };

struct TerminalConfig {
    String ssid;
    String wifiPassword;
    String apiBaseUrl;
    String terminalId;
    String terminalToken;
    String deviceName;
};

struct TrustBundle {
    uint32_t version = 0;
    String warningAfter;
    String replaceBefore;
    String earliestCaExpiry;
    String effectiveMaintenanceDeadline;
    String certificates;
    bool valid = false;
};

struct OfflineScan {
    String requestId;
    String uid;
    String deviceTime;
    String reason;
    uint32_t sequence = 0;
};

LiquidCrystal_I2C lcd(LCD_ADDRESS, LCD_COLS, LCD_ROWS);
MFRC522 rfid(PIN_RC522_SS, PIN_RC522_RST);
Preferences preferences;
WebServer setupServer(80);
DNSServer dnsServer;

TerminalConfig config;
DeviceState state = DeviceState::BOOT;
unsigned long stateEnteredAt = 0;
unsigned long buttonDownSince = 0;
bool setupButtonWasPressedAtBoot = false;
bool setupPortalStarted = false;
bool webPortalStarted = false;
bool setupRoutesRegistered = false;
bool restartScheduled = false;
unsigned long restartAt = 0;
uint8_t wifiAttempt = 0;
unsigned long wifiAttemptStartedAt = 0;
String apiStatus = "not_checked";
String setupFormKey;
String portalSessionKey;
String lastApiTestSummary = "Noch nicht getestet";
String lastApiTestDetails = "";

String welcomeLines[4] = {"PK-WS TimeApp", "Tag vorhalten", "Bereit", ""};
String currentUid;
String lastUid;
String currentRequestId;
String currentDeviceTime;
uint8_t currentScanAttempt = 0;
unsigned long nextScanAttemptAt = 0;
unsigned long lastUidAt = 0;
unsigned long resultUntil = 0;
unsigned long nextApiRetryAt = 0;
String savedDisplayLines[4] = {"PK-WS TimeApp", "Tag vorhalten", "Bereit", ""};
bool temporaryDisplayActive = false;
unsigned long temporaryDisplayUntil = 0;
DeviceState temporaryDisplayState = DeviceState::BOOT;
bool resumeScanAfterWifiReconnect = false;
bool filesystemMounted = false;
bool timeSyncStarted = false;
unsigned long timeSyncStartedAt = 0;
unsigned long lastTrustCheckAt = 0;
uint32_t bootCounter = 0;
TlsState tlsState = TlsState::NOT_CHECKED;
ScanLifecycle scanLifecycle = ScanLifecycle::NONE;
TrustBundle activeTrust;
String activeTrustSource = "factory";
String trustStatus = "not_checked";
String recoveryStatus = "none";
String lastTerminalError;
String uploadedTrustBundle;
bool trustUploadTooLarge = false;

enum class LedTestState {
    OFF,
    RED,
    YELLOW,
    GREEN
};

LedTestState ledTestState = LedTestState::OFF;
unsigned long ledTestNextAt = 0;
bool nfcTestActive = false;
String nfcTestUid;
String nfcTestDebug = "Noch nicht gestartet";
String nfcTestReaderVersion = "";
uint8_t nfcTestUidSize = 0;
unsigned long nfcTestUntil = 0;
unsigned long nfcTestResultVisibleUntil = 0;

uint16_t beepDurations[8] = {0};
uint8_t beepCount = 0;
uint8_t beepIndex = 0;
bool beepActive = false;
unsigned long beepStepUntil = 0;

void applySignalFromJson(JsonVariantConst root, const String &fallbackLed, const String &fallbackBeep);
bool persistCurrentScan(const String &reason);
bool apiGet(const String &path, String &body, int &status, String &why, bool authenticated = true);
bool installTrustBundle(const String &raw, bool allowRollback, String &why);
void finishTrustInstall(bool verified);
bool restorePreviousTrust(String &why);
void restoreFactoryTrust();
bool syncOneQueuedScan();
void enterState(DeviceState next);
String isoDeviceTimeOrNull();

String macSuffix()
{
    String mac = WiFi.macAddress();
    mac.replace(":", "");
    if (mac.length() <= 4) {
        return mac;
    }

    return mac.substring(mac.length() - 4);
}

String trimTrailingSlash(String value)
{
    value.trim();
    while (value.endsWith("/")) {
        value.remove(value.length() - 1);
    }

    return value;
}

ApiTransport transportFor(const String &baseUrl)
{
    String value = baseUrl;
    value.toLowerCase();
    if (value.startsWith("http://")) return ApiTransport::HTTP_PLAIN;
    if (value.startsWith("https://")) return ApiTransport::HTTPS_VERIFIED;
    return ApiTransport::INVALID;
}

String transportLabel()
{
    switch (transportFor(config.apiBaseUrl)) {
        case ApiTransport::HTTP_PLAIN: return "http";
        case ApiTransport::HTTPS_VERIFIED: return "https";
        default: return "invalid";
    }
}

String tlsStateLabel()
{
    switch (tlsState) {
        case TlsState::NOT_APPLICABLE: return "not-applicable";
        case TlsState::NOT_CHECKED: return "not-checked";
        case TlsState::TIME_INVALID: return "time-invalid";
        case TlsState::TRUST_MISSING: return "trust-missing";
        case TlsState::CONNECTING: return "connecting";
        case TlsState::VERIFIED: return "verified";
        case TlsState::VALIDATION_FAILED: return "validation-failed";
        case TlsState::RECOVERY: return "recovery";
    }
    return "not-checked";
}

bool isHttpsTransport()
{
    return transportFor(config.apiBaseUrl) == ApiTransport::HTTPS_VERIFIED;
}

bool isTimeValid()
{
    return time(nullptr) > 1704067200;
}

String normalizePem(String value)
{
    value.replace("\r\n", "\n");
    value.replace("\r", "\n");
    value.trim();
    return value;
}

void appendSignedField(String &out, const char *name, const String &value)
{
    out += name;
    out += ':';
    out += String(value.length());
    out += '\n';
    out += value;
    out += '\n';
}

String signedTrustPayload(JsonObjectConst payload)
{
    // This length-delimited UTF-8 protocol is shared with the PHP signer.
    String out = "PKWS-TERMINAL-TRUST-V1\n";
    appendSignedField(out, "format_version", String((uint32_t) (payload["format_version"] | 0)));
    appendSignedField(out, "bundle_version", String((uint32_t) (payload["bundle_version"] | 0)));
    appendSignedField(out, "created_at", String((const char *) (payload["created_at"] | "")));
    appendSignedField(out, "warning_after", String((const char *) (payload["warning_after"] | "")));
    appendSignedField(out, "replace_before", String((const char *) (payload["replace_before"] | "")));
    JsonArrayConst certificates = payload["certificates"].as<JsonArrayConst>();
    appendSignedField(out, "certificate_count", String(certificates.size()));
    for (JsonVariantConst certificate : certificates) {
        appendSignedField(out, "certificate", normalizePem(certificate.as<String>()));
    }
    return out;
}

bool verifyTrustSignature(JsonObjectConst payload, const char *signature)
{
    if (signature == nullptr || strlen(signature) == 0) return false;
    String signedPayload = signedTrustPayload(payload);
    uint8_t hash[32];
    if (mbedtls_sha256_ret(reinterpret_cast<const unsigned char *>(signedPayload.c_str()), signedPayload.length(), hash, 0) != 0) return false;
    size_t signatureLength = 0;
    if (mbedtls_base64_decode(nullptr, 0, &signatureLength, reinterpret_cast<const unsigned char *>(signature), strlen(signature)) != MBEDTLS_ERR_BASE64_BUFFER_TOO_SMALL || signatureLength > 128) return false;
    uint8_t decodedSignature[128];
    if (mbedtls_base64_decode(decodedSignature, sizeof(decodedSignature), &signatureLength, reinterpret_cast<const unsigned char *>(signature), strlen(signature)) != 0) return false;
    mbedtls_pk_context key;
    mbedtls_pk_init(&key);
    int parsed = mbedtls_pk_parse_public_key(&key, reinterpret_cast<const unsigned char *>(TRUST_SIGNING_PUBLIC_KEY), strlen(TRUST_SIGNING_PUBLIC_KEY) + 1);
    bool valid = parsed == 0 && mbedtls_pk_get_type(&key) == MBEDTLS_PK_ECKEY && mbedtls_pk_verify(&key, MBEDTLS_MD_SHA256, hash, sizeof(hash), decodedSignature, signatureLength) == 0;
    mbedtls_pk_free(&key);
    return valid;
}

String certificateDate(const mbedtls_x509_time &date)
{
    char buffer[24];
    snprintf(buffer, sizeof(buffer), "%04d-%02d-%02dT%02d:%02d:%02dZ", date.year, date.mon, date.day, date.hour, date.min, date.sec);
    return String(buffer);
}

bool parseTrustBundle(const String &raw, TrustBundle &bundle, String &why)
{
    if (raw.length() == 0 || raw.length() > MAX_TRUST_BUNDLE_BYTES) { why = "trust_bundle_too_large"; return false; }
    DynamicJsonDocument document(MAX_TRUST_BUNDLE_BYTES);
    if (deserializeJson(document, raw)) { why = "trust_bundle_json_invalid"; return false; }
    JsonObjectConst root = document.as<JsonObjectConst>();
    JsonObjectConst payload = root["payload"].as<JsonObjectConst>();
    if (payload.isNull() || String((const char *) (root["signature_algorithm"] | "")) != "ECDSA-P256-SHA256" || (uint32_t) (payload["format_version"] | 0) != 1) { why = "trust_bundle_format_invalid"; return false; }
    JsonArrayConst certificates = payload["certificates"].as<JsonArrayConst>();
    if (certificates.isNull() || certificates.size() == 0 || certificates.size() > MAX_TRUST_CERTIFICATES) { why = "trust_bundle_certificates_invalid"; return false; }
    if (!verifyTrustSignature(payload, root["signature"] | "")) { why = "trust_signature_invalid"; return false; }
    TrustBundle parsed;
    parsed.version = (uint32_t) (payload["bundle_version"] | 0);
    parsed.warningAfter = String((const char *) (payload["warning_after"] | ""));
    parsed.replaceBefore = String((const char *) (payload["replace_before"] | ""));
    if (parsed.version == 0) { why = "trust_bundle_version_invalid"; return false; }
    for (JsonVariantConst entry : certificates) {
        String certificate = normalizePem(entry.as<String>());
        if (!certificate.startsWith("-----BEGIN CERTIFICATE-----") || certificate.length() > 8192) { why = "trust_certificate_invalid"; return false; }
        mbedtls_x509_crt cert;
        mbedtls_x509_crt_init(&cert);
        int result = mbedtls_x509_crt_parse(&cert, reinterpret_cast<const unsigned char *>(certificate.c_str()), certificate.length() + 1);
        if (result != 0 || !cert.ca_istrue) { mbedtls_x509_crt_free(&cert); why = "trust_certificate_invalid"; return false; }
        String expiry = certificateDate(cert.valid_to);
        if (parsed.earliestCaExpiry.length() == 0 || expiry < parsed.earliestCaExpiry) parsed.earliestCaExpiry = expiry;
        mbedtls_x509_crt_free(&cert);
        parsed.certificates += certificate + "\n";
    }
    parsed.valid = true;
    bundle = parsed;
    return true;
}

bool writeFileAtomically(const char *target, const String &content, const char *temporary)
{
    File file = LittleFS.open(temporary, "w");
    if (!file) return false;
    bool written = file.print(content) == content.length();
    file.flush();
    file.close();
    if (!written) { LittleFS.remove(temporary); return false; }
    if (LittleFS.exists(target)) LittleFS.remove(target);
    return LittleFS.rename(temporary, target);
}

bool readTrustFile(const char *path, TrustBundle &bundle, String &why)
{
    if (!filesystemMounted || !LittleFS.exists(path)) return false;
    File file = LittleFS.open(path, "r");
    if (!file || file.size() > MAX_TRUST_BUNDLE_BYTES) { if (file) file.close(); return false; }
    String raw = file.readString();
    file.close();
    return parseTrustBundle(raw, bundle, why);
}

void loadFactoryTrust()
{
    activeTrust = TrustBundle();
    activeTrust.version = 0;
    activeTrust.certificates = String(FACTORY_CA_ANCHOR_1) + "\n" + String(FACTORY_CA_ANCHOR_2);
    activeTrust.valid = activeTrust.certificates.length() > 0;
    mbedtls_x509_crt cert;
    mbedtls_x509_crt_init(&cert);
    if (mbedtls_x509_crt_parse(&cert, reinterpret_cast<const unsigned char *>(activeTrust.certificates.c_str()), activeTrust.certificates.length() + 1) == 0) {
        activeTrust.earliestCaExpiry = certificateDate(cert.valid_to);
    }
    mbedtls_x509_crt_free(&cert);
    activeTrustSource = "factory";
}

time_t trustDateToEpoch(const String &value)
{
    struct tm parsed = {};
    if (value.length() == 0 || strptime(value.c_str(), "%Y-%m-%dT%H:%M:%SZ", &parsed) == nullptr) return 0;
    return mktime(&parsed);
}

void refreshTrustStatus()
{
    if (!isHttpsTransport()) { trustStatus = "not-applicable"; return; }
    if (!activeTrust.valid) { trustStatus = "invalid"; return; }
    if (!isTimeValid()) { trustStatus = "not-checked"; return; }
    time_t warningDeadline = trustDateToEpoch(activeTrust.warningAfter);
    time_t replaceDeadline = trustDateToEpoch(activeTrust.replaceBefore);
    time_t certificateDeadline = trustDateToEpoch(activeTrust.earliestCaExpiry);
    if (certificateDeadline > 0) certificateDeadline -= TRUST_WARNING_BUFFER_SECONDS;
    if (certificateDeadline > 0 && (warningDeadline == 0 || certificateDeadline < warningDeadline)) warningDeadline = certificateDeadline;
    activeTrust.effectiveMaintenanceDeadline = warningDeadline > 0 ? String((uint32_t) warningDeadline) : "";
    if (replaceDeadline > 0 && time(nullptr) >= replaceDeadline) trustStatus = "replace-required";
    else if (warningDeadline > 0 && time(nullptr) >= warningDeadline) trustStatus = "warning";
    else trustStatus = "current";
}

void recoverTrustAtBoot()
{
    if (!filesystemMounted) { loadFactoryTrust(); recoveryStatus = "filesystem_mount_failed"; return; }
    String why;
    TrustBundle active, previous, pending;
    bool hasActive = readTrustFile(TRUST_ACTIVE, active, why);
    bool hasPrevious = readTrustFile(TRUST_PREVIOUS, previous, why);
    bool hasPending = readTrustFile(TRUST_OLD_PENDING, pending, why);
    if (!hasActive && hasPending) {
        LittleFS.remove(TRUST_ACTIVE);
        LittleFS.rename(TRUST_OLD_PENDING, TRUST_ACTIVE);
        activeTrust = pending; activeTrustSource = "active"; recoveryStatus = "active_restored_from_pending";
    } else if (hasActive && hasPending) {
        LittleFS.remove(TRUST_PREVIOUS);
        LittleFS.rename(TRUST_OLD_PENDING, TRUST_PREVIOUS);
        activeTrust = active; activeTrustSource = "active"; recoveryStatus = "pending_promoted_to_previous";
    } else if (hasActive) {
        activeTrust = active; activeTrustSource = "active"; recoveryStatus = "none";
    } else if (hasPrevious) {
        LittleFS.remove(TRUST_ACTIVE);
        LittleFS.rename(TRUST_PREVIOUS, TRUST_ACTIVE);
        activeTrust = previous; activeTrustSource = "previous"; recoveryStatus = "previous_restored";
    } else {
        loadFactoryTrust(); recoveryStatus = "factory_fallback";
    }
    for (const char *path : {TRUST_STAGING, TRUST_NEW}) {
        TrustBundle ignored;
        if (LittleFS.exists(path) && !readTrustFile(path, ignored, why)) LittleFS.remove(path);
    }
    LittleFS.remove(TRUST_RECOVERY_MARKER);
    refreshTrustStatus();
}

bool installTrustBundle(const String &raw, bool allowRollback, String &why)
{
    TrustBundle candidate;
    if (!parseTrustBundle(raw, candidate, why)) return false;
    if (!allowRollback && activeTrust.valid && activeTrust.version > 0 && candidate.version <= activeTrust.version) { why = "trust_bundle_rollback"; return false; }
    if (!filesystemMounted) { why = "filesystem_mount_failed"; return false; }
    if (!writeFileAtomically(TRUST_NEW, raw, TRUST_STAGING)) { why = "trust_staging_write_failed"; return false; }
    TrustBundle reread;
    if (!readTrustFile(TRUST_NEW, reread, why)) { LittleFS.remove(TRUST_NEW); return false; }
    if (!writeFileAtomically(TRUST_RECOVERY_MARKER, String("install=") + String(candidate.version), "/trust-marker.tmp")) { why = "trust_marker_write_failed"; return false; }
    LittleFS.remove(TRUST_OLD_PENDING);
    if (LittleFS.exists(TRUST_ACTIVE) && !LittleFS.rename(TRUST_ACTIVE, TRUST_OLD_PENDING)) { why = "trust_active_backup_failed"; return false; }
    if (!LittleFS.rename(TRUST_NEW, TRUST_ACTIVE)) {
        if (LittleFS.exists(TRUST_OLD_PENDING)) LittleFS.rename(TRUST_OLD_PENDING, TRUST_ACTIVE);
        why = "trust_activate_failed"; return false;
    }
    if (LittleFS.exists(TRUST_OLD_PENDING)) { LittleFS.remove(TRUST_PREVIOUS); LittleFS.rename(TRUST_OLD_PENDING, TRUST_PREVIOUS); }
    activeTrust = candidate;
    activeTrustSource = "active";
    recoveryStatus = "installed_pending_verification";
    refreshTrustStatus();
    return true;
}

void finishTrustInstall(bool verified)
{
    if (verified) { LittleFS.remove(TRUST_RECOVERY_MARKER); recoveryStatus = "verified"; return; }
    TrustBundle previous; String why;
    if (readTrustFile(TRUST_PREVIOUS, previous, why)) {
        LittleFS.remove(TRUST_OLD_PENDING);
        if (LittleFS.rename(TRUST_ACTIVE, TRUST_OLD_PENDING) && LittleFS.rename(TRUST_PREVIOUS, TRUST_ACTIVE)) {
            activeTrust = previous;
            activeTrustSource = "previous";
            recoveryStatus = "rolled_back_to_previous";
        } else {
            recoveryStatus = "rollback_pending_recovery";
        }
    } else { loadFactoryTrust(); recoveryStatus = "rollback_factory"; }
}

bool restorePreviousTrust(String &why)
{
    TrustBundle previous;
    if (!readTrustFile(TRUST_PREVIOUS, previous, why)) return false;
    LittleFS.remove(TRUST_ACTIVE);
    if (!LittleFS.rename(TRUST_PREVIOUS, TRUST_ACTIVE)) { why = "trust_previous_activate_failed"; return false; }
    activeTrust = previous;
    activeTrustSource = "previous";
    recoveryStatus = "previous_restored";
    return true;
}

void restoreFactoryTrust()
{
    if (filesystemMounted) LittleFS.remove(TRUST_ACTIVE);
    loadFactoryTrust();
    recoveryStatus = "factory_selected";
}

String queuePath(uint32_t sequence, const char *suffix = ".json")
{
    return String(QUEUE_DIRECTORY) + "/" + String(sequence) + suffix;
}

uint32_t nextQueueSequence()
{
    if (!filesystemMounted) return 0;
    uint32_t sequence = 0;
    File file = LittleFS.open(QUEUE_SEQUENCE_FILE, "r");
    if (file) { sequence = (uint32_t) file.readString().toInt(); file.close(); }
    File directory = LittleFS.open(QUEUE_DIRECTORY, "r");
    if (directory && directory.isDirectory()) {
        for (File entry = directory.openNextFile(); entry; entry = directory.openNextFile()) {
            String name = entry.name();
            if (name.endsWith(".json")) {
                int slash = name.lastIndexOf('/');
                uint32_t existing = (uint32_t) name.substring(slash + 1, name.length() - 5).toInt();
                if (existing > sequence) sequence = existing;
            }
            entry.close();
        }
        directory.close();
    }
    sequence++;
    if (!writeFileAtomically(QUEUE_SEQUENCE_FILE, String(sequence), "/queue/sequence.tmp")) return 0;
    return sequence;
}

size_t queueDepth()
{
    if (!filesystemMounted) return 0;
    File directory = LittleFS.open(QUEUE_DIRECTORY, "r");
    if (!directory || !directory.isDirectory()) return 0;
    size_t count = 0;
    for (File entry = directory.openNextFile(); entry; entry = directory.openNextFile()) {
        String name = entry.name();
        if (name.endsWith(".json")) count++;
        entry.close();
    }
    directory.close();
    return count;
}

bool enqueueScan(const OfflineScan &scan, String &why)
{
    if (!filesystemMounted) { why = "filesystem_mount_failed"; return false; }
    if (queueDepth() >= MAX_QUEUE_ENTRIES) { why = "queue_full"; return false; }
    uint32_t sequence = nextQueueSequence();
    if (sequence == 0) { why = "queue_sequence_write_failed"; return false; }
    DynamicJsonDocument document(768);
    document["request_id"] = scan.requestId;
    document["nfc_uid"] = scan.uid;
    document["device_time"] = scan.deviceTime;
    document["firmware_version"] = FIRMWARE_VERSION;
    document["queued_reason"] = scan.reason;
    document["sequence"] = sequence;
    String body;
    serializeJson(document, body);
    String target = queuePath(sequence);
    String temporary = queuePath(sequence, ".tmp");
    if (!writeFileAtomically(target.c_str(), body, temporary.c_str())) { why = "queue_write_failed"; return false; }
    File check = LittleFS.open(target, "r");
    if (!check || check.size() == 0) { if (check) check.close(); LittleFS.remove(target); why = "queue_write_failed"; return false; }
    check.close();
    return true;
}

bool nextQueuedScan(OfflineScan &scan)
{
    if (!filesystemMounted) return false;
    File directory = LittleFS.open(QUEUE_DIRECTORY, "r");
    if (!directory || !directory.isDirectory()) return false;
    String selected;
    uint32_t selectedSequence = UINT32_MAX;
    for (File entry = directory.openNextFile(); entry; entry = directory.openNextFile()) {
        String name = entry.name();
        if (name.endsWith(".json")) {
            int slash = name.lastIndexOf('/');
            uint32_t sequence = (uint32_t) name.substring(slash + 1, name.length() - 5).toInt();
            if (sequence > 0 && sequence < selectedSequence) { selected = name; selectedSequence = sequence; }
        }
        entry.close();
    }
    directory.close();
    if (selected.length() == 0) return false;
    File file = LittleFS.open(selected, "r");
    if (!file || file.size() > 1024) {
        if (file) file.close();
        String corrupt = selected + ".corrupt";
        LittleFS.rename(selected.c_str(), corrupt.c_str());
        return false;
    }
    DynamicJsonDocument document(768);
    DeserializationError error = deserializeJson(document, file);
    file.close();
    if (error || !document["request_id"].is<const char *>() || !document["nfc_uid"].is<const char *>()) {
        String corrupt = selected + ".corrupt";
        LittleFS.rename(selected.c_str(), corrupt.c_str());
        return false;
    }
    scan.requestId = document["request_id"].as<String>();
    scan.uid = document["nfc_uid"].as<String>();
    scan.deviceTime = document["device_time"].as<String>();
    scan.reason = document["queued_reason"].as<String>();
    scan.sequence = (uint32_t) (document["sequence"] | 0);
    return true;
}

void acknowledgeQueuedScan(const OfflineScan &scan)
{
    String pending = queuePath(scan.sequence);
    String acknowledged = queuePath(scan.sequence, ".acked");
    if (LittleFS.rename(pending, acknowledged)) LittleFS.remove(acknowledged);
}

RetryClass retryClassFor(int status, const String &code)
{
    if (status == 408 || status == 429 || status >= 500 || status <= 0) return RetryClass::TEMPORARY;
    if (status == 400 || status == 401 || status == 403 || code == "terminal_disabled" || code == "terminal_auth_failed" || code == "nfc_tag_invalid") return RetryClass::PERMANENT;
    return status >= 200 && status < 300 ? RetryClass::PERMANENT : RetryClass::TEMPORARY;
}

String fitLcdLine(String value)
{
    value.replace("\r", " ");
    value.replace("\n", " ");
    value.trim();

    if (value.length() > LCD_COLS) {
        value = value.substring(0, LCD_COLS);
    }

    while (value.length() < LCD_COLS) {
        value += " ";
    }

    return value;
}

void lcdShowLines(const String lines[4])
{
    for (uint8_t row = 0; row < LCD_ROWS; row++) {
        lcd.setCursor(0, row);
        lcd.print(fitLcdLine(lines[row]));
    }
}

void lcdShow(const String &line1, const String &line2, const String &line3, const String &line4)
{
    String lines[4] = {line1, line2, line3, line4};
    lcdShowLines(lines);
}

void lcdShowTemporary(const String &line1, const String &line2, const String &line3, const String &line4, unsigned long holdMs)
{
    String lines[4] = {line1, line2, line3, line4};
    lcdShowLines(lines);
    temporaryDisplayActive = true;
    temporaryDisplayState = state;
    temporaryDisplayUntil = millis() + holdMs;
}

void rememberDisplay(const String lines[4])
{
    for (uint8_t i = 0; i < 4; i++) {
        savedDisplayLines[i] = lines[i];
    }
}

void restoreSavedDisplay()
{
    lcdShowLines(savedDisplayLines);
    temporaryDisplayActive = false;
}

void setAllLeds(bool red, bool yellow, bool green)
{
    digitalWrite(PIN_LED_RED, red ? HIGH : LOW);
    digitalWrite(PIN_LED_YELLOW, yellow ? HIGH : LOW);
    digitalWrite(PIN_LED_GREEN, green ? HIGH : LOW);
}

void applyLedSignal(const String &signal)
{
    if (signal == "green") {
        setAllLeds(false, false, true);
    } else if (signal == "red") {
        setAllLeds(true, false, false);
    } else if (signal == "yellow") {
        setAllLeds(false, true, false);
    } else {
        setAllLeds(false, true, false);
    }
}

void startBeepPattern(const uint16_t *durations, uint8_t count)
{
    beepCount = count > 8 ? 8 : count;
    for (uint8_t i = 0; i < beepCount; i++) {
        beepDurations[i] = durations[i];
    }

    beepIndex = 0;
    beepActive = beepCount > 0;
    if (beepActive) {
        digitalWrite(PIN_BUZZER, HIGH);
        beepStepUntil = millis() + beepDurations[0];
    }
}

void triggerBeep(const String &signal)
{
    static const uint16_t readyPattern[] = {80};
    static const uint16_t waitPattern[] = {60};
    static const uint16_t successPattern[] = {80, 80, 80};
    static const uint16_t errorPattern[] = {250, 120, 250, 120, 250};

    if (signal == "success") {
        startBeepPattern(successPattern, 3);
    } else if (signal == "error") {
        startBeepPattern(errorPattern, 5);
    } else if (signal == "ready") {
        startBeepPattern(readyPattern, 1);
    } else if (signal == "wait") {
        startBeepPattern(waitPattern, 1);
    }
}

void updateBuzzer()
{
    if (!beepActive || millis() < beepStepUntil) {
        return;
    }

    beepIndex++;
    if (beepIndex >= beepCount) {
        beepActive = false;
        digitalWrite(PIN_BUZZER, LOW);
        return;
    }

    digitalWrite(PIN_BUZZER, (beepIndex % 2 == 0) ? HIGH : LOW);
    beepStepUntil = millis() + beepDurations[beepIndex];
}

bool isSetupButtonPressed()
{
    return digitalRead(PIN_SETUP_BUTTON) == LOW;
}

bool loadConfig()
{
    preferences.begin(NVS_NAMESPACE, true);
    config.ssid = preferences.getString("ssid", "");
    config.wifiPassword = preferences.getString("wifi_password", "");
    config.apiBaseUrl = preferences.getString("api_base_url", "");
    config.terminalId = preferences.getString("terminal_id", "");
    config.terminalToken = preferences.getString("terminal_token", "");
    config.deviceName = preferences.getString("device_name", "");
    preferences.end();

    config.apiBaseUrl = trimTrailingSlash(config.apiBaseUrl);

    return config.ssid.length() > 0
        && config.apiBaseUrl.length() > 0
        && config.terminalId.length() > 0
        && config.terminalToken.length() > 0
        && transportFor(config.apiBaseUrl) != ApiTransport::INVALID;
}

void saveConfigFromRequest()
{
    String wifiPassword = setupServer.arg("wifi_password");
    if (wifiPassword.length() == 0 && config.wifiPassword.length() > 0) {
        wifiPassword = config.wifiPassword;
    }

    String terminalToken = setupServer.arg("terminal_token");
    if (terminalToken.length() == 0 && config.terminalToken.length() > 0) {
        terminalToken = config.terminalToken;
    }

    preferences.begin(NVS_NAMESPACE, false);
    preferences.putString("ssid", setupServer.arg("ssid"));
    preferences.putString("wifi_password", wifiPassword);
    preferences.putString("api_base_url", trimTrailingSlash(setupServer.arg("api_base_url")));
    preferences.putString("terminal_id", setupServer.arg("terminal_id"));
    preferences.putString("terminal_token", terminalToken);
    preferences.putString("device_name", setupServer.arg("device_name"));
    preferences.end();
}

void clearConfig()
{
    preferences.begin(NVS_NAMESPACE, false);
    preferences.clear();
    preferences.end();
    config = TerminalConfig();
}

String htmlEscape(String value)
{
    value.replace("&", "&amp;");
    value.replace("<", "&lt;");
    value.replace(">", "&gt;");
    value.replace("\"", "&quot;");
    return value;
}

bool setupPostAuthorized()
{
    String cookie = setupServer.header("Cookie");
    bool authenticated = portalSessionKey.length() > 0 && cookie.indexOf("pkws_portal=" + portalSessionKey) >= 0;
    return authenticated && setupFormKey.length() > 0 && setupServer.arg("setup_key") == setupFormKey;
}

String setupKeyInput()
{
    return String("<input type=\"hidden\" name=\"setup_key\" value=\"") + htmlEscape(setupFormKey) + "\">";
}

String portalIpLabel()
{
    if (WiFi.status() == WL_CONNECTED) {
        return WiFi.localIP().toString();
    }

    if (setupPortalStarted) {
        return WiFi.softAPIP().toString();
    }

    return "-";
}

int wifiRssi()
{
    return WiFi.status() == WL_CONNECTED ? WiFi.RSSI() : 0;
}

int wifiQualityPercent()
{
    if (WiFi.status() != WL_CONNECTED) {
        return 0;
    }

    int quality = (wifiRssi() + 100) * 2;
    if (quality < 0) {
        return 0;
    }
    if (quality > 100) {
        return 100;
    }

    return quality;
}

String wifiQualityLabel()
{
    int quality = wifiQualityPercent();
    if (quality >= 80) {
        return "sehr gut";
    }
    if (quality >= 60) {
        return "gut";
    }
    if (quality >= 40) {
        return "mittel";
    }
    if (quality > 0) {
        return "schwach";
    }

    return "nicht verbunden";
}

String wifiSignalLabel()
{
    if (WiFi.status() != WL_CONNECTED) {
        return "nicht verbunden";
    }

    return String(wifiRssi()) + " dBm / " + String(wifiQualityPercent()) + "% / " + wifiQualityLabel();
}

String rc522VersionLabel()
{
    byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
    String label = "0x";
    if (version < 0x10) {
        label += "0";
    }
    String hex = String(version, HEX);
    hex.toUpperCase();
    label += hex;

    return label;
}

bool rc522VersionLooksValid(const String &version)
{
    return version.length() > 0 && version != "0x00" && version != "0xFF";
}

void ensurePortalKeys()
{
    if (setupFormKey.length() == 0) {
        setupFormKey = String((uint32_t)esp_random(), HEX) + String((uint32_t)esp_random(), HEX);
        setupFormKey.toUpperCase();
    }

    if (portalSessionKey.length() == 0) {
        portalSessionKey = String((uint32_t)esp_random(), HEX) + String((uint32_t)esp_random(), HEX);
        portalSessionKey.toUpperCase();
    }
}

bool portalAuthenticated()
{
    String cookie = setupServer.header("Cookie");
    return portalSessionKey.length() > 0 && cookie.indexOf("pkws_portal=" + portalSessionKey) >= 0;
}

String loginHtml(const String &message = "")
{
    String page;
    page.reserve(2200);
    page += F("<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">");
    page += F("<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">");
    page += F("<title>PK-WS TimeApp Portal</title><style>");
    page += F(":root{font-family:Arial,sans-serif;color:#17202a;background:#f4f7f8}body{margin:0;padding:16px}main{max-width:420px;margin:0 auto}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:8px;padding:16px;margin-top:24px}h1{font-size:24px;margin:0 0 8px}.muted{color:#5f6f7a;font-size:14px}label{display:block;font-weight:700;margin:12px 0 4px}input{box-sizing:border-box;width:100%;min-height:44px;border:1px solid #b9c7d0;border-radius:6px;padding:10px;font-size:16px}button{width:100%;min-height:46px;border:0;border-radius:6px;background:#165d8f;color:#fff;font-weight:700;font-size:16px;margin:12px 0 0;padding:10px}.error{color:#a12b2b;font-weight:700}</style></head><body><main><section class=\"panel\">");
    page += F("<h1>PK-WS TimeApp</h1><p class=\"muted\">Terminal Portal Login</p>");
    if (message.length() > 0) {
        page += F("<p class=\"error\">");
        page += htmlEscape(message);
        page += F("</p>");
    }
    page += F("<form method=\"post\" action=\"/login\"><label>Portal-Passwort</label><input name=\"portal_password\" type=\"password\" required autofocus><button type=\"submit\">Einloggen</button></form>");
    page += F("</section></main></body></html>");
    return page;
}

String setupHtml()
{
    if (!portalAuthenticated()) {
        return loginHtml();
    }

    String maskedToken = config.terminalToken.length() > 0 ? "gespeichert" : "";
    String wifiLabel = "nicht verbunden";
    if (WiFi.status() == WL_CONNECTED) {
        wifiLabel = WiFi.SSID();
    } else if (setupPortalStarted) {
        wifiLabel = "Setup-AP";
    }

    String apiBaseLabel = "-";
    if (config.apiBaseUrl.length() > 0) {
        apiBaseLabel = config.apiBaseUrl;
    }

    String page;
    page.reserve(15000);
    page += F("<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">");
    page += F("<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">");
    page += F("<title>PK-WS TimeApp Setup</title><style>");
    page += F(":root{font-family:Arial,sans-serif;color:#17202a;background:#f4f7f8}body{margin:0;padding:16px}");
    page += F("main{max-width:680px;margin:0 auto}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:8px;padding:16px;margin:0 0 14px}");
    page += F("h1{font-size:24px;margin:0 0 8px}h2{font-size:18px;margin:0 0 12px}.muted{color:#5f6f7a;font-size:14px}");
    page += F("label{display:block;font-weight:700;margin:12px 0 4px}input{box-sizing:border-box;width:100%;min-height:44px;border:1px solid #b9c7d0;border-radius:6px;padding:10px;font-size:16px}");
    page += F("button{width:100%;min-height:46px;border:0;border-radius:6px;background:#165d8f;color:#fff;font-weight:700;font-size:16px;margin:8px 0;padding:10px}");
    page += F("button.secondary{background:#42525c}button.danger{background:#a12b2b}.net{display:flex;justify-content:space-between;border-top:1px solid #e4ecef;padding:10px 0;gap:8px}");
    page += F("button.good{background:#1f7a4d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.grid button{margin:0}");
    page += F("code,pre{word-break:break-all;white-space:pre-wrap}.status{display:grid;grid-template-columns:120px 1fr;gap:6px;font-size:14px}.result{background:#eef4f7;border-radius:6px;padding:10px;min-height:44px;white-space:pre-wrap;word-break:break-word}</style></head><body><main>");
    page += F("<section class=\"panel\"><h1>PK-WS TimeApp</h1><p class=\"muted\">Terminal Setup Portal</p><div class=\"status\">");
    page += F("<span>Firmware</span><code>");
    page += FIRMWARE_VERSION;
    page += F("</code><span>MAC</span><code>");
    page += WiFi.macAddress();
    page += F("</code><span>Portal-IP</span><code>");
    page += portalIpLabel();
    page += F("</code><span>WLAN</span><code>");
    page += htmlEscape(wifiLabel);
    page += F("</code><span>Signal</span><code>");
    page += htmlEscape(wifiSignalLabel());
    page += F("</code><span>API URL</span><code>");
    page += htmlEscape(apiBaseLabel);
    page += F("</code><span>API Status</span><code>");
    page += htmlEscape(apiStatus);
    page += F("</code><span>API Test</span><code>");
    page += htmlEscape(lastApiTestSummary);
    page += F("</code><span>Transport / TLS</span><code>");
    page += transportLabel() + " / " + tlsStateLabel();
    page += F("</code><span>Trust / Queue</span><code>");
    page += activeTrustSource + " v" + String(activeTrust.version) + " / " + trustStatus + " / " + String(queueDepth()) + "/" + String(MAX_QUEUE_ENTRIES);
    page += F("</code><span>Trust-Fristen</span><code>");
    page += htmlEscape("Warnung: " + activeTrust.warningAfter + " · Ersetzen: " + activeTrust.replaceBefore + " · CA: " + activeTrust.earliestCaExpiry);
    page += F("</code><span>NTP / Zeit</span><code>");
    page += isTimeValid() ? isoDeviceTimeOrNull() : "nicht synchronisiert";
    page += F("</code><span>Dateisystem</span><code>");
    page += filesystemMounted ? "eingebunden" : "FEHLER: nicht eingebunden";
    page += F("</code><span>Recovery / Fehler</span><code>");
    page += htmlEscape(recoveryStatus + " / " + lastTerminalError);
    page += F("</code></div><form method=\"post\" action=\"/logout\"><button class=\"secondary\" type=\"submit\">Ausloggen</button></form></section>");
    page += F("<section class=\"panel\"><h2>WLAN suchen</h2><button type=\"button\" onclick=\"scanWifi()\">WLANs suchen</button><div id=\"networks\" class=\"muted\">Noch nicht gesucht.</div></section>");
    page += F("<section class=\"panel\"><h2>Konfiguration</h2><form id=\"configForm\" method=\"post\" action=\"/save\">");
    page += setupKeyInput();
    page += F("<label>WLAN-SSID</label><input id=\"ssid\" name=\"ssid\" required value=\"");
    page += htmlEscape(config.ssid);
    page += F("\"><label>WLAN-Passwort</label><input name=\"wifi_password\" type=\"password\" placeholder=\"");
    page += config.wifiPassword.length() > 0 ? F("gespeichert - leer lassen zum Beibehalten") : F("");
    page += F("\"><label>TimeApp API Base URL</label><input name=\"api_base_url\" required placeholder=\"http://192.168.1.10\" value=\"");
    page += htmlEscape(config.apiBaseUrl);
    page += F("\"><label>Terminal-ID</label><input name=\"terminal_id\" required placeholder=\"terminal-empfang\" value=\"");
    page += htmlEscape(config.terminalId);
    page += F("\"><label>Terminal-Token</label><input name=\"terminal_token\" type=\"password\" placeholder=\"");
    page += maskedToken.length() > 0 ? F("gespeichert - leer lassen zum Beibehalten") : F("");
    page += F("\"><label>Geraetename optional</label><input name=\"device_name\" value=\"");
    page += htmlEscape(config.deviceName);
    page += F("\"><button type=\"submit\">Speichern und verbinden</button><button class=\"good\" type=\"button\" onclick=\"testApi()\">API testen</button></form>");
    page += F("<div id=\"apiResult\" class=\"result muted\">");
    page += htmlEscape(lastApiTestDetails.length() > 0 ? lastApiTestDetails : "Noch kein API-Test ausgefuehrt.");
    page += F("</div></section>");
    page += F("<section class=\"panel\"><h2>Diagnose</h2><p class=\"muted\">WLAN und Geraete einzeln pruefen.</p><div class=\"status\"><span>SSID</span><code id=\"diagSsid\">");
    page += htmlEscape(WiFi.status() == WL_CONNECTED ? WiFi.SSID() : String("-"));
    page += F("</code><span>WLAN Staerke</span><code id=\"diagSignal\">");
    page += htmlEscape(wifiSignalLabel());
    page += F("</code></div><button class=\"secondary\" type=\"button\" onclick=\"refreshDiag()\">WLAN aktualisieren</button><div class=\"grid\">");
    page += F("<button type=\"button\" onclick=\"postAction('/test/lcd','LCD-Test gestartet')\">LCD</button>");
    page += F("<button type=\"button\" onclick=\"postAction('/test/leds','LED-Test gestartet')\">LEDs</button>");
    page += F("<button type=\"button\" onclick=\"postAction('/test/buzzer','Buzzer-Test gestartet')\">Buzzer</button>");
    page += F("<button type=\"button\" onclick=\"startNfcTest()\">NFC Reader</button></div><div id=\"hardwareResult\" class=\"result muted\">Bereit fuer Hardwaretests.</div></section>");
    page += F("<section class=\"panel\"><form method=\"post\" action=\"/reset\" onsubmit=\"return confirm('Konfiguration wirklich loeschen?')\"><input type=\"hidden\" name=\"setup_key\" value=\"");
    page += htmlEscape(setupFormKey);
    page += F("\"><button class=\"danger\" type=\"submit\">Konfiguration loeschen</button></form>");
    page += F("<form method=\"post\" action=\"/reboot\" onsubmit=\"return confirm('Terminal neu starten?')\"><input type=\"hidden\" name=\"setup_key\" value=\"");
    page += htmlEscape(setupFormKey);
    page += F("\"><button class=\"secondary\" type=\"submit\">Neustart</button></form></section>");
    page += F("<section class=\"panel\"><h2>Trust und Queue</h2><form method=\"post\" action=\"/trust/check\">");
    page += setupKeyInput();
    page += F("<button class=\"secondary\" type=\"submit\">Signiertes Bundle pruefen</button></form><form method=\"post\" action=\"/trust/upload\" enctype=\"multipart/form-data\">");
    page += setupKeyInput();
    page += F("<input name=\"bundle\" type=\"file\" accept=\"application/json\" required><button class=\"secondary\" type=\"submit\">Signiertes Bundle hochladen</button></form><form method=\"post\" action=\"/trust/previous\">");
    page += setupKeyInput();
    page += F("<button class=\"secondary\" type=\"submit\">Previous aktivieren</button></form><form method=\"post\" action=\"/trust/factory\">");
    page += setupKeyInput();
    page += F("<button class=\"secondary\" type=\"submit\">Factory-Trust aktivieren</button></form><form method=\"post\" action=\"/queue/sync\">");
    page += setupKeyInput();
    page += F("<button class=\"secondary\" type=\"submit\">Queue synchronisieren</button></form><form method=\"post\" action=\"/filesystem/format\" onsubmit=\"return confirm('Offline-Queue und Trust-Dateien werden geloescht. Fortfahren?')\">");
    page += setupKeyInput();
    page += F("<input name=\"confirm_format\" placeholder=\"FORMATIEREN eingeben\"><button class=\"danger\" type=\"submit\">Dateisystem doppelt bestaetigt formatieren</button></form></section>");
    page += F("<script>const setupKey='");
    page += htmlEscape(setupFormKey);
    page += F("';async function scanWifi(){const box=document.getElementById('networks');box.textContent='Suche laeuft...';try{const r=await fetch('/scan-wifi?setup_key='+encodeURIComponent(setupKey));const d=await r.json();if(!r.ok)throw new Error(d.message||'WLAN-Scan nicht erlaubt.');if(!d.networks||!d.networks.length){box.textContent='Keine WLANs gefunden.';return;}box.innerHTML=d.networks.map(n=>'<div class=\"net\"><button type=\"button\" onclick=\"pickSsid(this.dataset.ssid)\" data-ssid=\"'+esc(n.ssid)+'\">'+esc(n.ssid)+'</button><span>'+n.rssi+' dBm</span></div>').join('');}catch(e){box.textContent=e.message||'WLAN-Scan fehlgeschlagen.';}}");
    page += F("function pickSsid(s){document.getElementById('ssid').value=s;}function formBody(form){const b=new URLSearchParams(new FormData(form));if(!b.has('setup_key'))b.set('setup_key',setupKey);return b;}");
    page += F("async function testApi(){const box=document.getElementById('apiResult');box.textContent='API-Test laeuft...';try{const r=await fetch('/test-api',{method:'POST',body:formBody(document.getElementById('configForm'))});const d=await r.json();box.textContent=JSON.stringify(d,null,2);}catch(e){box.textContent='API-Test fehlgeschlagen.';}}");
    page += F("function renderDiag(d){document.getElementById('diagSsid').textContent=d.ssid||'-';document.getElementById('diagSignal').textContent=(d.wifi_status==='connected')?(d.wifi_rssi_dbm+' dBm / '+d.wifi_quality_percent+'% / '+d.wifi_quality):'nicht verbunden';}");
    page += F("async function refreshDiag(){const box=document.getElementById('hardwareResult');box.textContent='WLAN-Diagnose wird aktualisiert...';try{const r=await fetch('/status');const d=await r.json();if(!r.ok)throw new Error(d.message||'Bitte neu einloggen.');renderDiag(d);box.textContent='WLAN: '+(d.ssid||'-')+'\\nSignal: '+(d.wifi_status==='connected'?(d.wifi_rssi_dbm+' dBm / '+d.wifi_quality_percent+'% / '+d.wifi_quality):'nicht verbunden')+'\\nIP: '+(d.sta_ip||d.ip||'-');}catch(e){box.textContent=e.message||'WLAN-Diagnose fehlgeschlagen.';}}");
    page += F("function renderNfc(d){return 'NFC Reader\\nRC522 Version: '+(d.reader_version||'-')+'\\nReader Status: '+(d.reader_ok?'OK':'Pruefen')+'\\nDebug: '+(d.debug||'-')+'\\nUID: '+(d.uid||'-')+'\\nUID Bytes: '+(d.uid_bytes||0)+'\\nRestzeit: '+(d.remaining_ms||0)+' ms';}");
    page += F("async function postAction(url,msg){const box=document.getElementById('hardwareResult');box.textContent=msg;try{const b=new URLSearchParams();b.set('setup_key',setupKey);const r=await fetch(url,{method:'POST',body:b});const d=await r.json();box.textContent=JSON.stringify(d,null,2);}catch(e){box.textContent='Test fehlgeschlagen.';}}");
    page += F("async function startNfcTest(){await postAction('/test/nfc/start','NFC-Test gestartet. Tag vorhalten.');pollNfc(0);}async function pollNfc(i){const box=document.getElementById('hardwareResult');try{const r=await fetch('/test/nfc/status?setup_key='+encodeURIComponent(setupKey));const d=await r.json();if(!r.ok)throw new Error(d.message||'Bitte neu einloggen.');box.textContent=renderNfc(d);if(d.active&&!d.uid&&i<20)setTimeout(()=>pollNfc(i+1),1000);}catch(e){box.textContent=e.message||'NFC-Status fehlgeschlagen.';}}");
    page += F("function esc(s){return String(s||'').replace(/[&<>\"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[m]));}</script>");
    page += F("</main></body></html>");
    return page;
}

void sendSetupStatus()
{
    DynamicJsonDocument doc(1536);
    if (!portalAuthenticated()) {
        doc["ok"] = false;
        doc["message"] = "Portal-Login erforderlich.";
        String body;
        serializeJson(doc, body);
        setupServer.send(401, "application/json", body);
        return;
    }

    doc["ok"] = true;
    doc["firmware_version"] = FIRMWARE_VERSION;
    doc["mac"] = WiFi.macAddress();
    doc["ip"] = portalIpLabel();
    doc["sta_ip"] = WiFi.status() == WL_CONNECTED ? WiFi.localIP().toString() : String("");
    doc["setup_ip"] = setupPortalStarted ? WiFi.softAPIP().toString() : String("");
    doc["ssid"] = WiFi.status() == WL_CONNECTED ? WiFi.SSID() : String("");
    doc["wifi_rssi_dbm"] = wifiRssi();
    doc["wifi_quality_percent"] = wifiQualityPercent();
    doc["wifi_quality"] = wifiQualityLabel();
    doc["wifi_status"] = WiFi.status() == WL_CONNECTED ? "connected" : "setup_ap";
    doc["api_base_url"] = config.apiBaseUrl;
    doc["api_status"] = apiStatus;
    doc["last_api_test"] = lastApiTestSummary;
    doc["transport"] = transportLabel();
    doc["tls_state"] = tlsStateLabel();
    doc["ntp_status"] = isTimeValid() ? "synchronized" : "not_synchronized";
    doc["trust_source"] = activeTrustSource;
    doc["trust_bundle_version"] = activeTrust.version;
    doc["earliest_ca_expiry"] = activeTrust.earliestCaExpiry;
    doc["trust_warning"] = trustStatus;
    doc["recovery_status"] = recoveryStatus;
    doc["offline_queue_depth"] = queueDepth();
    doc["filesystem_mounted"] = filesystemMounted;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["min_free_heap"] = ESP.getMinFreeHeap();
    doc["last_error"] = lastTerminalError;

    String body;
    serializeJson(doc, body);
    setupServer.send(200, "application/json", body);
}

void scanWifi()
{
    int count = WiFi.scanNetworks();
    DynamicJsonDocument doc(4096);
    doc["ok"] = true;
    JsonArray networks = doc.createNestedArray("networks");
    int limit = count < 0 ? 0 : (count > 20 ? 20 : count);

    for (int i = 0; i < limit; i++) {
        JsonObject network = networks.createNestedObject();
        network["ssid"] = WiFi.SSID(i);
        network["rssi"] = WiFi.RSSI(i);
        network["secure"] = WiFi.encryptionType(i) != WIFI_AUTH_OPEN;
    }

    WiFi.scanDelete();

    String body;
    serializeJson(doc, body);
    setupServer.send(200, "application/json", body);
}

void sendJson(DynamicJsonDocument &doc, int status = 200)
{
    String body;
    serializeJson(doc, body);
    setupServer.send(status, "application/json", body);
}

TerminalConfig configFromPortalRequest()
{
    TerminalConfig candidate = config;

    if (setupServer.hasArg("ssid")) {
        candidate.ssid = setupServer.arg("ssid");
    }

    if (setupServer.hasArg("wifi_password") && setupServer.arg("wifi_password").length() > 0) {
        candidate.wifiPassword = setupServer.arg("wifi_password");
    }

    if (setupServer.hasArg("api_base_url")) {
        candidate.apiBaseUrl = trimTrailingSlash(setupServer.arg("api_base_url"));
    }

    if (setupServer.hasArg("terminal_id")) {
        candidate.terminalId = setupServer.arg("terminal_id");
    }

    if (setupServer.hasArg("terminal_token") && setupServer.arg("terminal_token").length() > 0) {
        candidate.terminalToken = setupServer.arg("terminal_token");
    }

    if (setupServer.hasArg("device_name")) {
        candidate.deviceName = setupServer.arg("device_name");
    }

    candidate.ssid.trim();
    candidate.apiBaseUrl.trim();
    candidate.terminalId.trim();
    candidate.terminalToken.trim();
    candidate.deviceName.trim();

    return candidate;
}

bool apiFieldsReady(const TerminalConfig &candidate)
{
    return candidate.apiBaseUrl.length() > 0
        && candidate.terminalId.length() > 0
        && candidate.terminalToken.length() > 0;
}

void addApiHeadersFor(HTTPClient &http, const TerminalConfig &candidate)
{
    http.addHeader("X-Terminal-ID", candidate.terminalId);
    http.addHeader("Authorization", "Bearer " + candidate.terminalToken);
    http.addHeader("Content-Type", "application/json");
}

void handleApiTestRequest()
{
    DynamicJsonDocument response(4096);

    if (!setupPostAuthorized()) {
        response["ok"] = false;
        response["message"] = "Setup-Sitzung ungueltig.";
        sendJson(response, 403);
        return;
    }

    TerminalConfig candidate = configFromPortalRequest();
    if (!apiFieldsReady(candidate)) {
        response["ok"] = false;
        response["message"] = "API Base URL, Terminal-ID oder Terminal-Token fehlt.";
        lastApiTestSummary = "Eingaben unvollstaendig";
        lastApiTestDetails = "API Base URL, Terminal-ID oder Terminal-Token fehlt.";
        sendJson(response, 422);
        return;
    }

    if (WiFi.status() != WL_CONNECTED) {
        response["ok"] = false;
        response["message"] = "WLAN ist nicht verbunden.";
        lastApiTestSummary = "WLAN nicht verbunden";
        lastApiTestDetails = "WLAN ist nicht verbunden.";
        sendJson(response, 409);
        return;
    }

    ApiTransport candidateTransport = transportFor(candidate.apiBaseUrl);
    if (candidateTransport == ApiTransport::INVALID) {
        response["ok"] = false;
        response["message"] = "API URL muss mit http:// oder https:// beginnen.";
        sendJson(response, 422);
        return;
    }
    if (candidateTransport == ApiTransport::HTTPS_VERIFIED && (!isTimeValid() || !activeTrust.valid)) {
        response["ok"] = false;
        response["message"] = !isTimeValid() ? "HTTPS-Test benötigt synchronisierte Zeit." : "HTTPS-Test benötigt gültigen Trust.";
        sendJson(response, 409);
        return;
    }
    HTTPClient http;
    WiFiClient plain;
    WiFiClientSecure secure;
    http.setTimeout(HTTP_TIMEOUT_MS);
    String url = trimTrailingSlash(candidate.apiBaseUrl) + "/api/v1/terminal/config";
    bool begun = candidateTransport == ApiTransport::HTTP_PLAIN
        ? http.begin(plain, url)
        : (secure.setCACert(activeTrust.certificates.c_str()), http.begin(secure, url));
    if (!begun) {
        response["ok"] = false;
        response["message"] = "HTTP-Verbindung konnte nicht vorbereitet werden.";
        lastApiTestSummary = "HTTP Startfehler";
        lastApiTestDetails = "HTTP-Verbindung konnte nicht vorbereitet werden.";
        sendJson(response, 422);
        return;
    }

    addApiHeadersFor(http, candidate);
    int httpStatus = http.GET();
    String body = http.getString();
    http.end();

    response["http_status"] = httpStatus;

    if (httpStatus <= 0) {
        response["ok"] = false;
        response["message"] = "Keine Antwort von der TimeApp API.";
        lastApiTestSummary = "Keine API-Antwort";
        lastApiTestDetails = "Keine Antwort von der TimeApp API.";
        sendJson(response, 504);
        return;
    }

    DynamicJsonDocument apiDoc(4096);
    DeserializationError error = deserializeJson(apiDoc, body);
    if (error) {
        response["ok"] = false;
        response["message"] = "API-Antwort ist kein gueltiges JSON.";
        response["raw_length"] = body.length();
        lastApiTestSummary = "Ungueltiges JSON";
        lastApiTestDetails = "API-Antwort ist kein gueltiges JSON.";
        sendJson(response, 502);
        return;
    }

    bool ok = apiDoc["ok"] | false;
    response["ok"] = ok;
    response["api_ok"] = ok;
    response["code"] = apiDoc["code"] | "";
    response["message"] = apiDoc["message"] | "";
    response["server_time"] = apiDoc["server_time"] | "";
    JsonArray lines = response.createNestedArray("display_lines");

    String lcdLines[4] = {"API Test", ok ? "Verbindung OK" : "API Fehler", "siehe Portal", ""};
    if (apiDoc["display"]["lines"].is<JsonArrayConst>()) {
        JsonArrayConst apiLines = apiDoc["display"]["lines"].as<JsonArrayConst>();
        uint8_t index = 0;
        for (JsonVariantConst line : apiLines) {
            if (index >= 4) {
                break;
            }
            lcdLines[index] = line.as<String>();
            index++;
        }
    }

    for (uint8_t i = 0; i < 4; i++) {
        lines.add(lcdLines[i]);
    }

    if (ok && httpStatus >= 200 && httpStatus < 300) {
        lcdShowTemporary(lcdLines[0], lcdLines[1], lcdLines[2], lcdLines[3], 5000);
        applySignalFromJson(apiDoc.as<JsonVariantConst>(), "green", "ready");
    } else {
        lcdShowTemporary("API Test", "fehlgeschlagen", String("HTTP ") + httpStatus, "siehe Portal", 5000);
        applyLedSignal("red");
        triggerBeep("error");
    }

    lastApiTestSummary = ok ? "OK" : String("Fehler HTTP ") + httpStatus;
    String details;
    serializeJsonPretty(response, details);
    lastApiTestDetails = details;
    sendJson(response, 200);
}

void handleHardwareTestResponse(const String &name, const String &message)
{
    DynamicJsonDocument doc(512);
    doc["ok"] = true;
    doc["test"] = name;
    doc["message"] = message;
    sendJson(doc);
}

void scheduleRestart(unsigned long waitMs)
{
    restartScheduled = true;
    restartAt = millis() + waitMs;
}

void setupRoutes()
{
    if (setupRoutesRegistered) {
        return;
    }

    setupRoutesRegistered = true;
    static const char *headerKeys[] = {"Cookie"};
    setupServer.collectHeaders(headerKeys, 1);

    setupServer.on("/", HTTP_GET, []() {
        setupServer.send(200, "text/html", setupHtml());
    });

    setupServer.on("/login", HTTP_POST, []() {
        String password = setupServer.arg("portal_password");
        if (password == PORTAL_ADMIN_PASSWORD) {
            ensurePortalKeys();
            setupServer.sendHeader("Set-Cookie", "pkws_portal=" + portalSessionKey + "; Path=/; HttpOnly; SameSite=Lax");
            setupServer.sendHeader("Location", "/", true);
            setupServer.send(302, "text/plain", "");
            return;
        }

        setupServer.send(401, "text/html", loginHtml("Portal-Passwort ist falsch."));
    });

    setupServer.on("/logout", HTTP_POST, []() {
        setupServer.sendHeader("Set-Cookie", "pkws_portal=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax");
        setupServer.sendHeader("Location", "/", true);
        setupServer.send(302, "text/plain", "");
    });

    setupServer.on("/scan-wifi", HTTP_GET, []() {
        if (!setupPostAuthorized()) {
            DynamicJsonDocument doc(256);
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        scanWifi();
    });

    setupServer.on("/status", HTTP_GET, []() {
        sendSetupStatus();
    });

    setupServer.on("/save", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            setupServer.send(403, "text/html", "<p>Setup-Sitzung ungueltig. Bitte Seite neu laden.</p><p><a href=\"/\">Zurueck</a></p>");
            return;
        }

        String ssid = setupServer.arg("ssid");
        String apiBaseUrl = setupServer.arg("api_base_url");
        String terminalId = setupServer.arg("terminal_id");
        String terminalToken = setupServer.arg("terminal_token");
        ssid.trim();
        apiBaseUrl.trim();
        terminalId.trim();
        terminalToken.trim();

        if (ssid.length() == 0 || apiBaseUrl.length() == 0 || transportFor(apiBaseUrl) == ApiTransport::INVALID || terminalId.length() == 0 || (terminalToken.length() == 0 && config.terminalToken.length() == 0)) {
            setupServer.send(422, "text/html", "<p>Bitte SSID, HTTP/HTTPS API Base URL, Terminal-ID und Terminal-Token ausfuellen.</p><p><a href=\"/\">Zurueck</a></p>");
            return;
        }

        saveConfigFromRequest();
        loadConfig();
        lcdShow("Konfig gespeichert", "Neustart...", "", "");
        triggerBeep("success");
        setupServer.send(200, "text/html", "<p>Konfiguration gespeichert. Das Terminal startet neu.</p>");
        scheduleRestart(1500);
    });

    setupServer.on("/test-api", HTTP_POST, []() {
        handleApiTestRequest();
    });

    setupServer.on("/trust/check", HTTP_POST, []() {
        if (!setupPostAuthorized()) { setupServer.send(403, "text/plain", "Setup-Sitzung ungueltig."); return; }
        String raw, why;
        int status = 0;
        bool candidateInstalled = apiGet("/api/v1/terminal/trust-bundle", raw, status, why, false) && status == 200 && installTrustBundle(raw, false, why);
        bool installed = candidateInstalled;
        if (candidateInstalled) {
            String checkBody;
            int checkStatus = 0;
            installed = apiGet("/api/v1/terminal/config", checkBody, checkStatus, why) && checkStatus >= 200 && checkStatus < 300;
        }
        if (candidateInstalled) finishTrustInstall(installed);
        recoveryStatus = installed ? "portal_verified" : why;
        setupServer.send(installed ? 200 : 422, "text/plain", installed ? "Signiertes Trust-Bundle installiert und verifiziert." : "Trust-Bundle nicht installiert: " + why);
    });

    setupServer.on("/trust/upload", HTTP_POST, []() {
        if (!setupPostAuthorized()) { uploadedTrustBundle = ""; setupServer.send(403, "text/plain", "Setup-Sitzung ungueltig."); return; }
        String why;
        bool installed = !trustUploadTooLarge && uploadedTrustBundle.length() > 0 && installTrustBundle(uploadedTrustBundle, false, why);
        if (installed) {
            String checkBody;
            int checkStatus = 0;
            installed = apiGet("/api/v1/terminal/config", checkBody, checkStatus, why) && checkStatus >= 200 && checkStatus < 300;
            finishTrustInstall(installed);
        }
        uploadedTrustBundle = "";
        trustUploadTooLarge = false;
        setupServer.send(installed ? 200 : 422, "text/plain", installed ? "Signiertes Bundle installiert. HTTPS-Verbindung pruefen." : "Upload nicht installiert: " + (why.length() ? why : "ungueltig oder zu gross"));
    }, []() {
        HTTPUpload &upload = setupServer.upload();
        if (upload.status == UPLOAD_FILE_START) { uploadedTrustBundle = ""; trustUploadTooLarge = upload.type != "application/json"; }
        else if (upload.status == UPLOAD_FILE_WRITE) {
            if (trustUploadTooLarge || uploadedTrustBundle.length() + upload.currentSize > MAX_TRUST_BUNDLE_BYTES) { trustUploadTooLarge = true; return; }
            uploadedTrustBundle.concat(reinterpret_cast<const char *>(upload.buf), upload.currentSize);
        } else if (upload.status == UPLOAD_FILE_ABORTED) { uploadedTrustBundle = ""; trustUploadTooLarge = true; }
    });

    setupServer.on("/trust/previous", HTTP_POST, []() {
        if (!setupPostAuthorized()) { setupServer.send(403, "text/plain", "Setup-Sitzung ungueltig."); return; }
        String why;
        bool restored = restorePreviousTrust(why);
        setupServer.send(restored ? 200 : 422, "text/plain", restored ? "Vorheriges Bundle aktiv." : "Previous nicht aktivierbar: " + why);
    });

    setupServer.on("/trust/factory", HTTP_POST, []() {
        if (!setupPostAuthorized()) { setupServer.send(403, "text/plain", "Setup-Sitzung ungueltig."); return; }
        restoreFactoryTrust();
        setupServer.send(200, "text/plain", "Factory-Trust aktiv. Bitte HTTPS-Verbindung testen.");
    });

    setupServer.on("/queue/sync", HTTP_POST, []() {
        if (!setupPostAuthorized()) { setupServer.send(403, "text/plain", "Setup-Sitzung ungueltig."); return; }
        if (WiFi.status() != WL_CONNECTED) { setupServer.send(409, "text/plain", "WLAN nicht verbunden."); return; }
        enterState(DeviceState::QUEUE_SYNC);
        setupServer.send(202, "text/plain", "Queue-Synchronisierung wurde gestartet.");
    });

    setupServer.on("/filesystem/format", HTTP_POST, []() {
        if (!setupPostAuthorized() || setupServer.arg("confirm_format") != "FORMATIEREN") { setupServer.send(403, "text/plain", "Doppelte Formatbestaetigung fehlt."); return; }
        if (!filesystemMounted) { setupServer.send(409, "text/plain", "Dateisystem nicht eingebunden."); return; }
        bool formatted = LittleFS.format();
        filesystemMounted = formatted && LittleFS.begin(false);
        if (filesystemMounted) LittleFS.mkdir(QUEUE_DIRECTORY);
        setupServer.send(formatted ? 200 : 500, "text/plain", formatted ? "Dateisystem formatiert. Neustart erforderlich." : "Formatierung fehlgeschlagen.");
    });

    setupServer.on("/test/lcd", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            DynamicJsonDocument doc(256);
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        lcdShowTemporary("LCD Test", "Zeile 2 OK", "Zeile 3 OK", "Zeile 4 OK", 5000);
        handleHardwareTestResponse("lcd", "LCD-Test fuer 5 Sekunden angezeigt.");
    });

    setupServer.on("/test/leds", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            DynamicJsonDocument doc(256);
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        ledTestState = LedTestState::RED;
        ledTestNextAt = millis();
        lcdShowTemporary("LED Test", "Rot Gelb Gruen", "bitte schauen", "", 5000);
        handleHardwareTestResponse("leds", "LED-Test gestartet.");
    });

    setupServer.on("/test/buzzer", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            DynamicJsonDocument doc(256);
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        static const uint16_t buzzerTestPattern[] = {80, 80, 80, 160, 250, 120, 250};
        startBeepPattern(buzzerTestPattern, 7);
        lcdShowTemporary("Buzzer Test", "Tonfolge", "bitte hoeren", "", 4000);
        handleHardwareTestResponse("buzzer", "Buzzer-Test gestartet.");
    });

    setupServer.on("/test/nfc/start", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            DynamicJsonDocument doc(256);
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        nfcTestActive = true;
        nfcTestUid = "";
        nfcTestUidSize = 0;
        nfcTestReaderVersion = rc522VersionLabel();
        nfcTestDebug = rc522VersionLooksValid(nfcTestReaderVersion) ? "RC522 bereit, warte auf Tag." : "RC522 antwortet nicht sauber.";
        nfcTestUntil = millis() + 15000;
        nfcTestResultVisibleUntil = 0;
        lcdShowTemporary("NFC Test", "Tag vorhalten", "15 Sekunden", "", 15000);
        handleHardwareTestResponse("nfc", "NFC-Test gestartet. Tag vorhalten.");
    });

    setupServer.on("/test/nfc/status", HTTP_GET, []() {
        DynamicJsonDocument doc(512);
        if (!setupPostAuthorized()) {
            doc["ok"] = false;
            doc["message"] = "Setup-Sitzung ungueltig.";
            sendJson(doc, 403);
            return;
        }

        doc["ok"] = true;
        doc["active"] = nfcTestActive;
        doc["reader_version"] = nfcTestReaderVersion;
        doc["reader_ok"] = rc522VersionLooksValid(nfcTestReaderVersion);
        doc["debug"] = nfcTestDebug;
        bool exposeUid = nfcTestActive || (nfcTestResultVisibleUntil > 0 && millis() < nfcTestResultVisibleUntil);
        doc["uid"] = exposeUid ? nfcTestUid : String("");
        doc["uid_bytes"] = exposeUid ? nfcTestUidSize : 0;
        doc["remaining_ms"] = nfcTestActive && nfcTestUntil > millis() ? (uint32_t)(nfcTestUntil - millis()) : 0;
        sendJson(doc);
    });

    setupServer.on("/reset", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            setupServer.send(403, "text/html", "<p>Setup-Sitzung ungueltig. Bitte Seite neu laden.</p><p><a href=\"/\">Zurueck</a></p>");
            return;
        }

        clearConfig();
        lcdShow("Konfiguration", "geloescht", "Neustart...", "");
        triggerBeep("ready");
        setupServer.send(200, "text/html", "<p>Konfiguration geloescht. Das Terminal startet neu und oeffnet danach den Setup-Modus.</p>");
        scheduleRestart(1500);
    });

    setupServer.on("/reboot", HTTP_POST, []() {
        if (!setupPostAuthorized()) {
            setupServer.send(403, "text/html", "<p>Setup-Sitzung ungueltig. Bitte Seite neu laden.</p><p><a href=\"/\">Zurueck</a></p>");
            return;
        }

        lcdShow("Neustart", "bitte warten", "", "");
        setupServer.send(200, "text/html", "<p>Neustart...</p>");
        scheduleRestart(1000);
    });

    setupServer.onNotFound([]() {
        setupServer.sendHeader("Location", String("http://") + portalIpLabel() + "/", true);
        setupServer.send(302, "text/plain", "");
    });
}

void startSetupPortal()
{
    if (setupPortalStarted) {
        return;
    }

    setupPortalStarted = true;
    WiFi.disconnect(false, false);
    WiFi.mode(WIFI_AP_STA);
    IPAddress apIp(192, 168, 4, 1);
    IPAddress netmask(255, 255, 255, 0);
    WiFi.softAPConfig(apIp, apIp, netmask);

    String apSsid = "PKWS-TimeApp-Setup-" + macSuffix();
    ensurePortalKeys();
    WiFi.softAP(apSsid.c_str(), SETUP_AP_PASSWORD);
    dnsServer.start(53, "*", apIp);
    setupRoutes();
    if (!webPortalStarted) {
        setupServer.begin();
        webPortalStarted = true;
    }

    Serial.print(F("Setup portal active. SSID: "));
    Serial.println(apSsid);
    lcdShow("Setup WLAN", "Endung " + macSuffix(), "IP 192.168.4.1", "Handy verbinden");
    setAllLeds(false, true, false);
}

void startWebPortal()
{
    ensurePortalKeys();
    setupRoutes();
    if (!webPortalStarted) {
        setupServer.begin();
        webPortalStarted = true;
        Serial.println(F("Web portal active on terminal IP."));
    }
}

void enterState(DeviceState next)
{
    state = next;
    stateEnteredAt = millis();
    if (next != temporaryDisplayState) {
        temporaryDisplayActive = false;
    }

    if (next == DeviceState::CONFIG_CHECK) {
        lcdShow("Konfig pruefen", "bitte warten", "", "");
    } else if (next == DeviceState::SETUP_MODE) {
        startSetupPortal();
    } else if (next == DeviceState::WIFI_CONNECT) {
        wifiAttempt = 0;
        wifiAttemptStartedAt = 0;
        setAllLeds(false, true, false);
    } else if (next == DeviceState::TIME_SYNC) {
        timeSyncStarted = true;
        timeSyncStartedAt = millis();
        configTime(0, 0, "pool.ntp.org", "time.nist.gov");
        lcdShow("Zeit synchronisieren", "HTTPS benötigt NTP", "bitte warten", "");
    } else if (next == DeviceState::API_CONFIG) {
        lcdShow("API pruefen", "Terminal config", "bitte warten", "");
        setAllLeds(false, true, false);
    } else if (next == DeviceState::READY) {
        resumeScanAfterWifiReconnect = false;
        lcdShowLines(welcomeLines);
        rememberDisplay(welcomeLines);
        setAllLeds(false, false, true);
    } else if (next == DeviceState::NFC_SCAN) {
        setAllLeds(false, false, true);
    } else if (next == DeviceState::TLS_RECOVERY) {
        tlsState = TlsState::RECOVERY;
        recoveryStatus = "running";
        lcdShow("TLS Recovery", "Scan gespeichert", "Trust wird", "geprueft");
    } else if (next == DeviceState::QUEUE_SYNC) {
        lcdShow("Offline Queue", "Synchronisierung", "bitte warten", "");
    } else if (next == DeviceState::ERROR_RETRY) {
        nextApiRetryAt = millis() + API_RETRY_MS;
    }
}

void handleSetupButton()
{
    if (state == DeviceState::SETUP_MODE) {
        return;
    }

    if (isSetupButtonPressed()) {
        if (buttonDownSince == 0) {
            buttonDownSince = millis();
        }

        if (millis() - buttonDownSince >= SETUP_BUTTON_HOLD_MS) {
            apiStatus = "setup_button";
            lcdShow("Setup-Taster", "gehalten", "Setup startet", "");
            triggerBeep("ready");
            enterState(DeviceState::SETUP_MODE);
        }
    } else {
        buttonDownSince = 0;
    }
}

String httpUrl(const String &path)
{
    return trimTrailingSlash(config.apiBaseUrl) + path;
}

void addApiHeaders(HTTPClient &http)
{
    http.addHeader("X-Terminal-ID", config.terminalId);
    http.addHeader("Authorization", "Bearer " + config.terminalToken);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Terminal-Firmware", FIRMWARE_VERSION);
    http.addHeader("X-Terminal-Transport", transportLabel());
    http.addHeader("X-Terminal-TLS-State", tlsStateLabel());
    http.addHeader("X-Terminal-Trust-Version", String(activeTrust.version));
    http.addHeader("X-Terminal-Trust-State", trustStatus);
    http.addHeader("X-Terminal-Queue-Depth", String(queueDepth()));
    http.addHeader("X-Terminal-Recovery-Status", recoveryStatus);
}

bool beginApiRequest(HTTPClient &http, WiFiClient &plain, WiFiClientSecure &secure, const String &url, String &why)
{
    http.setTimeout(HTTP_TIMEOUT_MS);
    ApiTransport transport = transportFor(config.apiBaseUrl);
    if (transport == ApiTransport::HTTP_PLAIN) {
        tlsState = TlsState::NOT_APPLICABLE;
        return http.begin(plain, url);
    }
    if (transport != ApiTransport::HTTPS_VERIFIED) { why = "api_url_invalid"; return false; }
    if (!isTimeValid()) { tlsState = TlsState::TIME_INVALID; why = "tls_time_invalid"; return false; }
    if (!activeTrust.valid || activeTrust.certificates.length() == 0) { tlsState = TlsState::TRUST_MISSING; why = "tls_trust_missing"; return false; }
    tlsState = TlsState::CONNECTING;
    secure.setCACert(activeTrust.certificates.c_str());
    return http.begin(secure, url);
}

bool isTlsTrustFailure(WiFiClientSecure &client)
{
    char errorBuffer[160] = {};
    if (client.lastError(errorBuffer, sizeof(errorBuffer)) == 0) return false;
    String error(errorBuffer);
    error.toLowerCase();
    return error.indexOf("certificate") >= 0 || error.indexOf("x509") >= 0 || error.indexOf("verify") >= 0 || error.indexOf("ca ") >= 0;
}

bool apiGet(const String &path, String &body, int &status, String &why, bool authenticated)
{
    WiFiClient plain;
    WiFiClientSecure secure;
    HTTPClient http;
    if (!beginApiRequest(http, plain, secure, httpUrl(path), why)) return false;
    if (authenticated) addApiHeaders(http);
    status = http.GET();
    if (status > 0) body = http.getString();
    http.end();
    if (status <= 0) {
        if (isHttpsTransport() && isTlsTrustFailure(secure)) { tlsState = TlsState::VALIDATION_FAILED; why = "tls_validation_failed"; }
        else why = "http_connect_failed";
        return false;
    }
    if (isHttpsTransport()) tlsState = TlsState::VERIFIED;
    return true;
}

bool readLimitedResponse(HTTPClient &http, String &body, size_t limit)
{
    WiFiClient *stream = http.getStreamPtr();
    int remaining = http.getSize();
    while (http.connected() && (remaining > 0 || remaining == -1)) {
        size_t available = stream->available();
        if (available == 0) { delay(1); continue; }
        if (body.length() + available > limit) return false;
        uint8_t buffer[256];
        size_t read = stream->readBytes(buffer, min(available, sizeof(buffer)));
        body.concat(reinterpret_cast<const char *>(buffer), read);
        if (remaining > 0) remaining -= read;
    }
    return body.length() <= limit;
}

bool recoveryDownload(String &bundle, String &why)
{
    // The sole insecure call: fixed same-origin public GET, without headers or body.
    if (!isHttpsTransport()) { why = "tls_recovery_not_applicable"; return false; }
    WiFiClientSecure client;
    client.setInsecure();
    HTTPClient http;
    http.setTimeout(HTTP_TIMEOUT_MS);
    if (!http.begin(client, httpUrl("/api/v1/terminal/trust-bundle"))) { why = "tls_recovery_connect_failed"; return false; }
    int status = http.GET();
    bool read = status == 200 && readLimitedResponse(http, bundle, MAX_TRUST_BUNDLE_BYTES);
    http.end();
    if (!read) { why = status == 200 ? "trust_bundle_too_large" : "tls_recovery_failed"; return false; }
    return true;
}

void applyDisplayFromJson(JsonVariantConst root, const String localFallback[4])
{
    String lines[4] = {localFallback[0], localFallback[1], localFallback[2], localFallback[3]};

    if (root["display"]["lines"].is<JsonArrayConst>()) {
        JsonArrayConst apiLines = root["display"]["lines"].as<JsonArrayConst>();
        uint8_t index = 0;
        for (JsonVariantConst line : apiLines) {
            if (index >= 4) {
                break;
            }
            lines[index] = line.as<String>();
            index++;
        }
    }

    lcdShowLines(lines);
}

unsigned long holdMsFromJson(JsonVariantConst root)
{
    unsigned long holdMs = root["display"]["hold_ms"] | 15000;
    return constrain(holdMs, 1000UL, 60000UL);
}

void applySignalFromJson(JsonVariantConst root, const String &fallbackLed, const String &fallbackBeep)
{
    String led = root["signal"]["led"] | fallbackLed;
    String beep = root["signal"]["beep"] | fallbackBeep;
    applyLedSignal(led);
    triggerBeep(beep);
}

bool fetchApiConfig()
{
    if (WiFi.status() != WL_CONNECTED) {
        apiStatus = "wifi_disconnected";
        return false;
    }

    String body, why;
    int status = 0;
    if (!apiGet("/api/v1/terminal/config", body, status, why)) {
        apiStatus = why;
        return false;
    }

    if (status <= 0) {
        apiStatus = "api_unreachable";
        String lines[4] = {"API Fehler", "keine Antwort", "Retry folgt", ""};
        lcdShowLines(lines);
        applyLedSignal("red");
        triggerBeep("error");
        return false;
    }

    DynamicJsonDocument doc(4096);
    DeserializationError error = deserializeJson(doc, body);
    if (error) {
        apiStatus = "invalid_json";
        String lines[4] = {"API Fehler", "JSON ungueltig", "Bitte Admin", "informieren"};
        lcdShowLines(lines);
        applyLedSignal("red");
        triggerBeep("error");
        return false;
    }

    String fallback[4] = {"API Fehler", "Terminal config", "nicht OK", ""};
    applyDisplayFromJson(doc.as<JsonVariantConst>(), fallback);
    applySignalFromJson(doc.as<JsonVariantConst>(), status >= 200 && status < 300 ? "green" : "red", status >= 200 && status < 300 ? "ready" : "error");

    bool ok = doc["ok"] | false;
    if (status >= 200 && status < 300 && ok) {
        for (uint8_t i = 0; i < 4; i++) {
            welcomeLines[i] = doc["display"]["lines"][i] | welcomeLines[i];
        }
        uint32_t advertisedVersion = (uint32_t) (doc["trust_bundle"]["latest_version"] | 0);
        if (isHttpsTransport() && advertisedVersion > activeTrust.version
            && (lastTrustCheckAt == 0 || millis() - lastTrustCheckAt >= 24UL * 60UL * 60UL * 1000UL)) {
            lastTrustCheckAt = millis();
            String bundle, trustWhy;
            int trustStatusCode = 0;
            bool candidateInstalled = apiGet("/api/v1/terminal/trust-bundle", bundle, trustStatusCode, trustWhy, false)
                && trustStatusCode == 200 && installTrustBundle(bundle, false, trustWhy);
            if (candidateInstalled) {
                String verifyBody;
                int verifyStatus = 0;
                bool verified = apiGet("/api/v1/terminal/config", verifyBody, verifyStatus, trustWhy)
                    && verifyStatus >= 200 && verifyStatus < 300;
                finishTrustInstall(verified);
                recoveryStatus = verified ? "automatic_update_verified" : trustWhy;
            } else if (trustWhy.length() > 0) {
                lastTerminalError = trustWhy;
            }
        }
        apiStatus = "ok";
        return true;
    }

    apiStatus = String("api_error_") + status;
    return false;
}

String isoDeviceTimeOrNull()
{
    if (!isTimeValid()) {
        return "";
    }

    struct tm timeInfo;
    if (!getLocalTime(&timeInfo, 10)) {
        return "";
    }

    char buffer[32];
    strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", &timeInfo);
    return String(buffer);
}

String generateRequestId()
{
    String mac = WiFi.macAddress();
    mac.replace(":", "");
    String randomPart = String((uint32_t)esp_random(), HEX);
    randomPart.toUpperCase();
    return "pkws-" + mac + "-" + String(bootCounter) + "-" + String(millis()) + "-" + randomPart;
}

String normalizeUid(MFRC522::Uid *uid)
{
    String normalized;
    for (byte i = 0; i < uid->size; i++) {
        if (i > 0) {
            normalized += ":";
        }
        String part = String(uid->uidByte[i], HEX);
        part.toUpperCase();
        if (part.length() < 2) {
            part = "0" + part;
        }
        normalized += part;
    }

    return normalized;
}

bool sendScanRequest()
{
    if (WiFi.status() != WL_CONNECTED) {
        apiStatus = "wifi_disconnected";
        return false;
    }

    DynamicJsonDocument request(512);
    request["request_id"] = currentRequestId;
    request["nfc_uid"] = currentUid;
    String deviceTime = currentDeviceTime.length() > 0 ? currentDeviceTime : isoDeviceTimeOrNull();
    if (deviceTime.length() > 0) {
        request["device_time"] = deviceTime;
    } else {
        request["device_time"] = nullptr;
    }
    request["firmware_version"] = FIRMWARE_VERSION;

    String body;
    serializeJson(request, body);

    WiFiClient plain;
    WiFiClientSecure secure;
    HTTPClient http;
    String why;
    if (!beginApiRequest(http, plain, secure, httpUrl("/api/v1/terminal/scan"), why)) { apiStatus = why; return false; }
    addApiHeaders(http);
    int status = http.POST(body);
    String response = status > 0 ? http.getString() : "";
    http.end();

    if (status <= 0) {
        bool trustFailure = isHttpsTransport() && isTlsTrustFailure(secure);
        if (trustFailure) tlsState = TlsState::VALIDATION_FAILED;
        apiStatus = trustFailure ? "tls_validation_failed" : "scan_unreachable";
        return false;
    }
    if (isHttpsTransport()) tlsState = TlsState::VERIFIED;

    DynamicJsonDocument doc(8192);
    DeserializationError error = deserializeJson(doc, response);
    if (error) {
        apiStatus = "scan_invalid_json";
        return false;
    }

    String code = doc["code"] | "";
    RetryClass classification = retryClassFor(status, code);
    if (status < 200 || status >= 300) {
        if (classification == RetryClass::TEMPORARY) {
            apiStatus = String("scan_temporary_") + status;
            return false;
        }
        String fallbackRejected[4] = {"Scan abgelehnt", "Bitte Admin", "informieren", ""};
        applyDisplayFromJson(doc.as<JsonVariantConst>(), fallbackRejected);
        applySignalFromJson(doc.as<JsonVariantConst>(), "red", "error");
        scanLifecycle = ScanLifecycle::REJECTED;
        currentUid = "";
        currentRequestId = "";
        currentDeviceTime = "";
        resultUntil = millis() + holdMsFromJson(doc.as<JsonVariantConst>());
        apiStatus = String("scan_rejected_") + status;
        enterState(DeviceState::SHOW_RESULT);
        return true;
    }

    String fallback[4] = {"Scan Antwort", "empfangen", "", ""};
    applyDisplayFromJson(doc.as<JsonVariantConst>(), fallback);
    applySignalFromJson(doc.as<JsonVariantConst>(), status >= 200 && status < 300 ? "green" : "red", status >= 200 && status < 300 ? "success" : "error");
    resultUntil = millis() + holdMsFromJson(doc.as<JsonVariantConst>());
    apiStatus = "scan_ok";
    scanLifecycle = ScanLifecycle::SENT_CONFIRMED;
    currentUid = "";
    currentRequestId = "";
    currentDeviceTime = "";
    enterState(DeviceState::SHOW_RESULT);
    return true;
}

bool persistCurrentScan(const String &reason)
{
    if (currentUid.length() == 0 || currentRequestId.length() == 0) return true;
    OfflineScan scan;
    scan.requestId = currentRequestId;
    scan.uid = currentUid;
    scan.deviceTime = currentDeviceTime.length() > 0 ? currentDeviceTime : isoDeviceTimeOrNull();
    scan.reason = reason;
    String why;
    if (!enqueueScan(scan, why)) {
        lastTerminalError = why;
        lcdShow("Scan nicht", "gespeichert", why, "Admin informieren");
        applyLedSignal("red");
        triggerBeep("error");
        resultUntil = millis() + 15000;
        enterState(DeviceState::SHOW_RESULT);
        return false;
    }
    scanLifecycle = ScanLifecycle::PERSISTED;
    currentUid = "";
    currentRequestId = "";
    currentDeviceTime = "";
    return true;
}

bool syncOneQueuedScan()
{
    OfflineScan queued;
    if (!nextQueuedScan(queued)) return false;
    currentUid = queued.uid;
    currentRequestId = queued.requestId;
    currentDeviceTime = queued.deviceTime;
    scanLifecycle = ScanLifecycle::PERSISTED;
    bool sent = sendScanRequest();
    if (scanLifecycle == ScanLifecycle::SENT_CONFIRMED || scanLifecycle == ScanLifecycle::REJECTED) {
        acknowledgeQueuedScan(queued);
    }
    if (scanLifecycle == ScanLifecycle::PERSISTED) {
        currentUid = "";
        currentRequestId = "";
        currentDeviceTime = "";
    }
    return sent;
}

void startWifiAttempt()
{
    wifiAttempt++;
    wifiAttemptStartedAt = millis();
    if (setupPortalStarted) {
        dnsServer.stop();
        WiFi.softAPdisconnect(true);
        setupPortalStarted = false;
    }
    WiFi.mode(WIFI_STA);
    WiFi.begin(config.ssid.c_str(), config.wifiPassword.c_str());
    lcdShow("WLAN verbindet", config.ssid, "Versuch " + String(wifiAttempt) + "/" + String(WIFI_MAX_ATTEMPTS), "bitte warten");
    setAllLeds(false, true, false);

    Serial.print(F("Connecting to WiFi SSID: "));
    Serial.println(config.ssid);
}

void handleWifiConnect()
{
    if (wifiAttempt == 0) {
        startWifiAttempt();
        return;
    }

    if (WiFi.status() == WL_CONNECTED) {
        apiStatus = "wifi_connected";
        lcdShow("WLAN verbunden", WiFi.localIP().toString(), "API wird geprueft", "");
        setAllLeds(false, false, true);
        startWebPortal();
        enterState(isHttpsTransport() ? DeviceState::TIME_SYNC : DeviceState::API_CONFIG);
        return;
    }

    if (millis() - wifiAttemptStartedAt < WIFI_ATTEMPT_MS) {
        return;
    }

    if (wifiAttempt < WIFI_MAX_ATTEMPTS) {
        WiFi.disconnect(false, false);
        startWifiAttempt();
        return;
    }

    apiStatus = "wifi_failed";
    lcdShow("WLAN Fehler", "Setup startet", "Bitte neu", "einrichten");
    applyLedSignal("red");
    triggerBeep("error");
    enterState(DeviceState::SETUP_MODE);
}

void checkWifiHealth()
{
    if (state == DeviceState::SETUP_MODE || state == DeviceState::BOOT || state == DeviceState::CONFIG_CHECK || state == DeviceState::WIFI_CONNECT || state == DeviceState::SHOW_RESULT) {
        return;
    }

    if (WiFi.status() != WL_CONNECTED) {
        if (state == DeviceState::SEND_SCAN && currentUid.length() > 0 && currentRequestId.length() > 0) {
            persistCurrentScan("wifi_lost");
        }

        apiStatus = "wifi_lost";
        lcdShow("WLAN getrennt", "Reconnect", "bitte warten", "");
        triggerBeep("error");
        enterState(DeviceState::WIFI_CONNECT);
    }
}

void updateLedTest()
{
    if (ledTestState == LedTestState::OFF) {
        if (ledTestNextAt > 0 && millis() >= ledTestNextAt) {
            ledTestNextAt = 0;
            setAllLeds(false, false, state == DeviceState::READY || state == DeviceState::NFC_SCAN);
        }
        return;
    }

    if (millis() < ledTestNextAt) {
        return;
    }

    if (ledTestState == LedTestState::RED) {
        setAllLeds(true, false, false);
        ledTestState = LedTestState::YELLOW;
        ledTestNextAt = millis() + 700;
    } else if (ledTestState == LedTestState::YELLOW) {
        setAllLeds(false, true, false);
        ledTestState = LedTestState::GREEN;
        ledTestNextAt = millis() + 700;
    } else if (ledTestState == LedTestState::GREEN) {
        setAllLeds(false, false, true);
        ledTestState = LedTestState::OFF;
        ledTestNextAt = millis() + 700;
    }
}

void updateTemporaryDisplay()
{
    if (temporaryDisplayActive && millis() >= temporaryDisplayUntil) {
        if (state == temporaryDisplayState) {
            restoreSavedDisplay();
        } else {
            temporaryDisplayActive = false;
        }
    }
}

void updateNfcTest()
{
    if (!nfcTestActive) {
        if (nfcTestResultVisibleUntil > 0 && millis() >= nfcTestResultVisibleUntil) {
            nfcTestUid = "";
            nfcTestUidSize = 0;
            nfcTestResultVisibleUntil = 0;
            nfcTestDebug = "Test beendet.";
        }
        return;
    }

    if (millis() >= nfcTestUntil) {
        nfcTestActive = false;
        nfcTestResultVisibleUntil = millis() + 5000;
        if (nfcTestUid.length() == 0) {
            nfcTestDebug = "Timeout: kein Tag gelesen.";
            lcdShowTemporary("NFC Test", "kein Tag", "erkannt", "", 3000);
        }
        return;
    }

    if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
        return;
    }

    nfcTestUid = normalizeUid(&rfid.uid);
    nfcTestUidSize = rfid.uid.size;
    nfcTestDebug = "Tag gelesen, UID normalisiert.";
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    lastUid = nfcTestUid;
    lastUidAt = millis();
    nfcTestActive = false;
    nfcTestResultVisibleUntil = millis() + 30000;
    lcdShowTemporary("NFC Test OK", nfcTestUid, "UID gelesen", "", 5000);
    applyLedSignal("green");
    triggerBeep("success");
}

void updateHardwareTests()
{
    updateLedTest();
    updateNfcTest();
    updateTemporaryDisplay();
}

void handleNfcScan()
{
    if (nfcTestActive) {
        return;
    }

    if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
        return;
    }

    String uid = normalizeUid(&rfid.uid);
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();

    unsigned long now = millis();
    if (uid == lastUid && now - lastUidAt < DUPLICATE_UID_WINDOW_MS) {
        return;
    }

    lastUid = uid;
    lastUidAt = now;
    currentUid = uid;
    currentRequestId = generateRequestId();
    currentDeviceTime = isoDeviceTimeOrNull();
    currentScanAttempt = 0;
    nextScanAttemptAt = now;

    lcdShow("Tag erkannt", "Anfrage laeuft", "bitte warten", "");
    applyLedSignal("yellow");
    triggerBeep("wait");
    enterState(DeviceState::SEND_SCAN);
}

void handleSendScan()
{
    if (millis() < nextScanAttemptAt) {
        return;
    }

    currentScanAttempt++;
    if (sendScanRequest()) {
        return;
    }

    if (apiStatus == "tls_validation_failed" || apiStatus == "tls_time_invalid" || apiStatus == "tls_trust_missing") {
        if (persistCurrentScan(apiStatus)) enterState(DeviceState::TLS_RECOVERY);
        return;
    }

    if (currentScanAttempt < 3) {
        lcdShow("API Retry", "Scan wird", "wiederholt", "bitte warten");
        applyLedSignal("yellow");
        nextScanAttemptAt = millis() + (currentScanAttempt == 1 ? 1000 : 3000);
        return;
    }
    if (persistCurrentScan("network_failed")) {
        lcdShow("Scan gespeichert", "wird später", "synchronisiert", "");
        applyLedSignal("yellow");
        triggerBeep("wait");
        resultUntil = millis() + 8000;
        enterState(DeviceState::SHOW_RESULT);
    }
}

void handleBoot()
{
    unsigned long elapsed = millis() - stateEnteredAt;
    if (elapsed < 150) {
        setAllLeds(true, false, false);
    } else if (elapsed < 300) {
        setAllLeds(false, true, false);
    } else if (elapsed < 450) {
        setAllLeds(false, false, true);
    } else {
        setAllLeds(false, false, false);
    }

    if (setupButtonWasPressedAtBoot) {
        if (isSetupButtonPressed() && elapsed >= SETUP_BUTTON_HOLD_MS) {
            apiStatus = "boot_setup_button";
            enterState(DeviceState::SETUP_MODE);
            return;
        }

        if (!isSetupButtonPressed() && elapsed >= 1600) {
            enterState(DeviceState::CONFIG_CHECK);
            return;
        }
    } else if (elapsed >= 1600) {
        enterState(DeviceState::CONFIG_CHECK);
    }
}

void setup()
{
    Serial.begin(115200);
    setenv("TZ", "UTC0", 1);
    tzset();
    pinMode(PIN_LED_GREEN, OUTPUT);
    pinMode(PIN_LED_RED, OUTPUT);
    pinMode(PIN_LED_YELLOW, OUTPUT);
    pinMode(PIN_BUZZER, OUTPUT);
    pinMode(PIN_SETUP_BUTTON, INPUT_PULLUP);
    setAllLeds(false, false, false);
    digitalWrite(PIN_BUZZER, LOW);

    Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
    lcd.init();
    lcd.backlight();
    SPI.begin();
    rfid.PCD_Init();
    filesystemMounted = LittleFS.begin(false);
    if (filesystemMounted) {
        LittleFS.mkdir(QUEUE_DIRECTORY);
    }
    preferences.begin(NVS_NAMESPACE, false);
    bootCounter = preferences.getUInt("boot_counter", 0) + 1;
    preferences.putUInt("boot_counter", bootCounter);
    preferences.end();

    setupButtonWasPressedAtBoot = isSetupButtonPressed();
    stateEnteredAt = millis();
    lcdShow("PK-WS TimeApp", "Terminal startet", FIRMWARE_VERSION, "bitte warten");
    triggerBeep("ready");

    Serial.print(F("Firmware: "));
    Serial.println(FIRMWARE_VERSION);
    Serial.print(F("MAC: "));
    Serial.println(WiFi.macAddress());
    if (!filesystemMounted) {
        Serial.println(F("LittleFS mount failed; no automatic format was attempted."));
        lastTerminalError = "filesystem_mount_failed";
    }
    if (strcmp(SETUP_AP_PASSWORD, "change-me-setup") == 0 || strcmp(PORTAL_ADMIN_PASSWORD, "change-me-portal") == 0) {
        Serial.println(F("SECURITY WARNING: default portal credentials are configured; production flashing is forbidden."));
        lastTerminalError = "default_portal_credentials";
    }
}

void loop()
{
    updateBuzzer();
    updateHardwareTests();
    handleSetupButton();

    if (restartScheduled && millis() >= restartAt) {
        ESP.restart();
    }

    if (setupPortalStarted) {
        dnsServer.processNextRequest();
    }

    if (webPortalStarted) {
        setupServer.handleClient();
    }

    if (state != DeviceState::SETUP_MODE) {
        checkWifiHealth();
    }

    switch (state) {
        case DeviceState::BOOT:
            handleBoot();
            break;

        case DeviceState::CONFIG_CHECK:
            if (loadConfig()) {
                Serial.println(F("Configuration loaded. Secrets are hidden."));
                recoverTrustAtBoot();
                enterState(DeviceState::WIFI_CONNECT);
            } else {
                Serial.println(F("No valid configuration found. Starting setup mode."));
                enterState(DeviceState::SETUP_MODE);
            }
            break;

        case DeviceState::SETUP_MODE:
            break;

        case DeviceState::WIFI_CONNECT:
            handleWifiConnect();
            break;

        case DeviceState::TIME_SYNC: {
            unsigned long elapsed = millis() - timeSyncStartedAt;
            if (isTimeValid()) {
                enterState(DeviceState::API_CONFIG);
                break;
            }
            if (elapsed < TIME_SYNC_TIMEOUT_MS) {
                lcdShow("Zeit synchronisieren", "HTTPS benötigt NTP", String((TIME_SYNC_TIMEOUT_MS - elapsed) / 1000) + " Sekunden", "");
                break;
            }
            tlsState = TlsState::TIME_INVALID;
            apiStatus = "ntp_timeout";
            lcdShow("HTTPS blockiert", "NTP Timeout", "Retry folgt", "");
            enterState(DeviceState::ERROR_RETRY);
            break;
        }

        case DeviceState::API_CONFIG:
            if (fetchApiConfig()) {
                if (queueDepth() > 0) {
                    enterState(DeviceState::QUEUE_SYNC);
                } else if (resumeScanAfterWifiReconnect && currentUid.length() > 0 && currentRequestId.length() > 0) {
                    lcdShow("Scan Fortsetzung", "WLAN wieder da", "sende Anfrage", "");
                    nextScanAttemptAt = millis();
                    enterState(DeviceState::SEND_SCAN);
                } else {
                    enterState(DeviceState::READY);
                }
            } else {
                enterState(tlsState == TlsState::VALIDATION_FAILED ? DeviceState::TLS_RECOVERY : DeviceState::ERROR_RETRY);
            }
            break;

        case DeviceState::TLS_RECOVERY: {
            String bundle, why;
            bool installed = recoveryDownload(bundle, why) && installTrustBundle(bundle, false, why);
            bool recovered = installed;
            if (installed) {
                String verifyBody;
                int verifyStatus = 0;
                recovered = apiGet("/api/v1/terminal/config", verifyBody, verifyStatus, why) && verifyStatus >= 200 && verifyStatus < 300;
            }
            if (installed) finishTrustInstall(recovered);
            recoveryStatus = recovered ? "recovered" : (why.length() > 0 ? why : "tls_recovery_failed");
            if (recovered) enterState(queueDepth() > 0 ? DeviceState::QUEUE_SYNC : DeviceState::READY);
            else enterState(DeviceState::ERROR_RETRY);
            break;
        }

        case DeviceState::QUEUE_SYNC:
            if (queueDepth() == 0) {
                enterState(DeviceState::READY);
            } else {
                syncOneQueuedScan();
            }
            break;

        case DeviceState::READY:
            enterState(DeviceState::NFC_SCAN);
            break;

        case DeviceState::NFC_SCAN:
            handleNfcScan();
            break;

        case DeviceState::SEND_SCAN:
            handleSendScan();
            break;

        case DeviceState::SHOW_RESULT:
            if (millis() >= resultUntil) {
                enterState(queueDepth() > 0 ? DeviceState::QUEUE_SYNC : DeviceState::READY);
            }
            break;

        case DeviceState::ERROR_RETRY:
            if (millis() >= nextApiRetryAt) {
                enterState(DeviceState::API_CONFIG);
            }
            break;
    }
}
