/*
  ESP32 Unified Control System v4.0
  Funktionen: LED-Lauflicht, I2C LCD 20x4, SPI RFID-RC522, KY-012 Buzzer & Terminal-Parser
  
  Verdrahtung LCD (I2C):
  - SDA -> GPIO 21 | SCL -> GPIO 22
  
  Verdrahtung RFID-RC522 (VSPI):
  - SDA/SS -> GPIO 5   | SCK -> GPIO 18 
  - MOSI   -> GPIO 23  | MISO -> GPIO 19 
  - RST    -> GPIO 27  | 3.3V -> 3V3 
  
  Verdrahtung Aktiver Buzzer (KY-012):
  - Signal -> GPIO 32  | VCC -> 3.3V / 5V | GND -> GND

  Verdrahtung LEDs:
  - Rote LED -> GPIO 26 | Grüne LED -> GPIO 25 | Gelbe LED -> GPIO 33
*/

#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <SPI.h>
#include <MFRC522.h>

// --- Hardware-Definitionen ---
#define LED_RED     26   
#define LED_GRUN    25  
#define LED_YELL    33  
#define BUZZER_PIN  32  // Pin für den KY-012 Aktiven Buzzer

// SPI Pins für RC522
#define SS_PIN  5
#define RST_PIN 27

// --- Objekt-Instanzierungen ---
const int ledPins[] = {LED_BUILTIN, LED_RED, LED_GRUN, LED_YELL};
const int numLeds = sizeof(ledPins) / sizeof(ledPins[0]);

LiquidCrystal_I2C lcd(0x27, 20, 4); 
MFRC522 mfrc522(SS_PIN, RST_PIN);   

// --- Zustandsvariablen: Animation ---
bool animationRunning = false;     
unsigned long previousMillisAnim = 0;  
const long animInterval = 400;         
int animIndex = 0;                 
bool turningOnPhase = true;        

// --- Zustandsvariablen: RFID Scanner ---
bool rfidModeActive = false;
unsigned long rfidStartTime = 0;
const unsigned long rfidTimeout = 10000; 

// --- Serieller Eingabepuffer ---
const int MAX_BUFFER_SIZE = 64;
char inputBuffer[MAX_BUFFER_SIZE];
int bufferIndex = 0;

// --- Funktions-Prototypen ---
void updateDisplayLine(int line, String text);
void parseUnifiedCommand(String command);
void runAnimationStep();
void handleRFIDScan();
void toggleSingleLED(int pin, String ledName);
void triggerBuzzer(int durationMs);
void turnOffAll();
void resetAnimation();
void printHelpMenu();

void setup() {
  Serial.begin(115200);
  delay(100);

  // --- Bus-Initialisierungen ---
  Wire.begin(21, 22);       
  SPI.begin();              
  
  lcd.init();
  lcd.backlight();
  mfrc522.PCD_Init();       

  // --- Hardware-Pins konfigurieren ---
  for (int i = 0; i < numLeds; i++) {
    pinMode(ledPins[i], OUTPUT);
    digitalWrite(ledPins[i], LOW);
  }
  
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW); // Sicherstellen, dass der Buzzer still ist

  // --- Initiale Anzeige ---
  updateDisplayLine(0, "  ESP32 Core Ready  ");
  updateDisplayLine(1, "LED:R,G,Y,B Anim:A,S");
  updateDisplayLine(2, "RFID: C | Buzzer: P ");
  updateDisplayLine(3, "Status: Bereit      ");

  printHelpMenu();
  
  // Kurzer Start-Piepser als akustisches Feedback für erfolgreichen Boot
  triggerBuzzer(100);
}

void loop() {
  // 1. Serielle Schnittstelle abfragen (Asynchron)
  while (Serial.available() > 0) {
    char incomingChar = Serial.read();

    if (incomingChar == '\n' || incomingChar == '\r') {
      if (bufferIndex > 0) {
        inputBuffer[bufferIndex] = '\0'; 
        parseUnifiedCommand(String(inputBuffer));
        bufferIndex = 0;                 
      }
    } else if (bufferIndex < MAX_BUFFER_SIZE - 1) {
      inputBuffer[bufferIndex++] = incomingChar;
    }
  }

  // 2. Lauflicht-Animation abarbeiten (Nicht-blockierend)
  if (animationRunning) {
    unsigned long currentMillis = millis();
    if (currentMillis - previousMillisAnim >= animInterval) {
      previousMillisAnim = currentMillis;
      runAnimationStep();
    }
  }

  // 3. RFID-Scanner überwachen (Nicht-blockierend)
  if (rfidModeActive) {
    handleRFIDScan();
  }
}

/**
 * Erzeugt einen Ton mit der angegebenen Dauer.
 * Da es sich um einen aktiven Buzzer handelt, reicht ein digitales Schalten.
 */
void triggerBuzzer(int durationMs) {
  digitalWrite(BUZZER_PIN, HIGH); // Ton an
  delay(durationMs);              // Dauer warten
  digitalWrite(BUZZER_PIN, LOW);  // Ton aus
}

/**
 * Verarbeitet die Anfragen aus dem aktiven RFID-Modus
 */
void handleRFIDScan() {
  if (millis() - rfidStartTime > rfidTimeout) {
    rfidModeActive = false;
    updateDisplayLine(2, "-> Scan abgebrochen ");
    updateDisplayLine(3, "Status: Timeout     ");
    Serial.println("-> RFID-Scan abgebrochen (10 Sekunden Timeout).");
    
    // Akustisches Fehlersignal (zwei kurze, tiefe/hintereinander folgende Töne simuliert durch Abstände)
    triggerBuzzer(80);
    delay(80);
    triggerBuzzer(80);
    return;
  }

  if (!mfrc522.PICC_IsNewCardPresent()) {
    return; 
  }
  
  if (!mfrc522.PICC_ReadCardSerial()) {
    return; 
  }

  // UID extrahieren
  String uidString = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) {
      uidString += "0"; 
    }
    uidString += String(mfrc522.uid.uidByte[i], HEX);
    if (i < mfrc522.uid.size - 1) uidString += " "; 
  }
  uidString.toUpperCase(); 

  // Akustische Erfolgsmeldung: Sofortiger Piepton
  triggerBuzzer(200);

  // LCD und Terminal aktualisieren
  updateDisplayLine(2, "UID erkannt:        ");
  updateDisplayLine(3, uidString);
  Serial.println("\n-> ERFOLG! RFID-Chip erkannt.");
  Serial.println("-> UID lautet: " + uidString + "\n");

  mfrc522.PICC_HaltA();
  rfidModeActive = false; 
}

/**
 * Parser für Terminal-Befehle (LCD, LEDs, RFID, Buzzer)
 */
void parseUnifiedCommand(String command) {
  command.trim();
  if (command.length() == 0) return;

  // FALL A: LCD-Befehl (Format: [1-4]:Text)
  if (command.length() >= 2 && command.charAt(1) == ':') {
    char lineIndicator = command.charAt(0);
    if (lineIndicator >= '1' && lineIndicator <= '4') {
      int lineIndex = lineIndicator - '1';
      String message = command.substring(2);
      updateDisplayLine(lineIndex, message);
      Serial.println("-> LCD Zeile " + String(lineIndicator) + " aktualisiert.");
      return;
    }
  }

  // FALL B: Einzelzeichen (Steuerung)
  if (command.length() == 1) {
    char cmd = toupper(command.charAt(0));
    switch (cmd) {
      case 'R': toggleSingleLED(LED_RED, "Rot"); break;
      case 'G': toggleSingleLED(LED_GRUN, "Gruen"); break;
      case 'Y': toggleSingleLED(LED_YELL, "Gelb"); break;
      case 'B': toggleSingleLED(LED_BUILTIN, "Built-in"); break;
      
      case 'A':
        if (!animationRunning) {
          animationRunning = true;
          resetAnimation();
          Serial.println("-> Lauflicht GESTARTET.");
          updateDisplayLine(3, "Status: Lauflicht...");
        }
        break;
        
      case 'S':
        if (animationRunning) {
          animationRunning = false;
          turnOffAll();
          Serial.println("-> Lauflicht GESTOPPT.");
          updateDisplayLine(3, "Status: Bereit      ");
        }
        break;

      case 'C':
        if (!rfidModeActive) {
          rfidModeActive = true;
          rfidStartTime = millis(); 
          updateDisplayLine(2, ">> RFID Scanner <<  ");
          updateDisplayLine(3, "Bitte Chip vorhalten");
          Serial.println("-> SCAN-MODUS AKTIV: Bitte Chip innerhalb von 10 Sekunden vorhalten.");
        } else {
          Serial.println("-> Scanner laeuft bereits.");
        }
        break;

      case 'P':
        Serial.println("-> Befehl erhalten: Manuelle Buzzer-Aktivierung.");
        updateDisplayLine(3, "Buzzer: Test-Piep   ");
        triggerBuzzer(150);
        delay(100); // Kleine Entprellung/Pause nach Aktion
        updateDisplayLine(3, "Status: Bereit      ");
        break;
        
      default:
        Serial.println("-> Unbekannter Befehl.");
        break;
    }
    return;
  }
  Serial.println("-> Syntaxfehler.");
}

/**
 * Helper: Manuelles Umschalten der LEDs
 */
void toggleSingleLED(int pin, String ledName) {
  if (animationRunning) {
    Serial.println("-> Fehler: Bitte zuerst Animation (S) stoppen!");
    return;
  }
  bool newState = !digitalRead(pin);
  digitalWrite(pin, newState);
  Serial.println("-> LED " + ledName + (newState ? " AN." : " AUS."));
}

/**
 * Helper: Lauflicht-Schritt ausführen
 */
void runAnimationStep() {
  if (turningOnPhase) {
    digitalWrite(ledPins[animIndex], HIGH);
    animIndex++;
    if (animIndex >= numLeds) {
      turningOnPhase = false;
      animIndex = 0;
    }
  } else {
    digitalWrite(ledPins[animIndex], LOW);
    animIndex++;
    if (animIndex >= numLeds) {
      turningOnPhase = true;
      animIndex = 0;
    }
  }
}

/**
 * Helper: LCD-Zeile beschreiben und Reste überschreiben
 */
void updateDisplayLine(int line, String text) {
  if (text.length() > 20) text = text.substring(0, 20);
  while (text.length() < 20) text += " ";
  lcd.setCursor(0, line);
  lcd.print(text);
}

void turnOffAll() {
  for (int i = 0; i < numLeds; i++) digitalWrite(ledPins[i], LOW);
}

void resetAnimation() {
  turnOffAll();
  animIndex = 0;
  turningOnPhase = true;
  previousMillisAnim = millis();
}

void printHelpMenu() {
  Serial.println("\n==================================================");
  Serial.println("         ESP32 MASTER STEUERUNGSSYSTEM v4.0       ");
  Serial.println("==================================================");
  Serial.println(" [1] LED:    R(ot), G(ruen), Y(Gelb), B(uilt-In)");
  Serial.println(" [2] ANIM:   A (Start), S (Stop)");
  Serial.println(" [3] LCD:    [1-4]:[Text] (z.B. 1:Hallo)");
  Serial.println(" [4] RFID:   C (Chip scannen)");
  Serial.println(" [5] BUZZER: P (Manueller Test-Piepton)");
  Serial.println("==================================================\n");
}