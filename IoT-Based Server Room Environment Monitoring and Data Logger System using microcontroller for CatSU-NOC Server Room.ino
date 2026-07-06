#include <ESP8266WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <ESP8266HTTPClient.h>
#include <ThingSpeak.h>
#include <UniversalTelegramBot.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <DHT.h>
#include <ArduinoOTA.h>
#include <ESP8266mDNS.h>
#include "config.h"

void handleOTATask();


// ------------------------------------------------------------
// Pin fallback definitions for generic ESP8266 board packages
// ------------------------------------------------------------
#ifndef D0
#define D0 16  // HL-01 flame digital sensor
#endif
#ifndef D1
#define D1 5   // SCL
#endif
#ifndef D2
#define D2 4   // SDA
#endif
#ifndef D5
#define D5 14  // DHT22 data pin
#endif
#ifndef D6
#define D6 12  // buzzer
#endif
#ifndef D7
#define D7 13  // relay
#endif

// ------------------------------------------------------------
// User configuration
// ------------------------------------------------------------
#define DHTPIN D5
#define DHTTYPE DHT22
#define BUZZER_PIN D6
#define BUZZER_ACTIVE_LOW true   // set false if your buzzer module is active-HIGH
#define RELAY_PIN D7
#define RELAY_ACTIVE_LOW true

// Fire/smoke safety sensors
#define FLAME_PIN D0     // HL-01 DO pin -> ESP8266 D0
#define MQ135_ANALOG_PIN A0     // MQ-135 AO pin -> ESP8266 A0
#define FLAME_ACTIVE_LOW true // most HL-01 modules output LOW when flame is detected

#define LCD_ADDRESS 0x27
#define LCD_COLUMNS 20
#define LCD_ROWS 4



// ================================
// WiFi Reconnect Configuration
// ================================
const char* WIFI_SSID     = CFG_WIFI_SSID;
const char* WIFI_PASSWORD = CFG_WIFI_PASSWORD;

// ThingSpeak
const bool ENABLE_THINGSPEAK = CFG_ENABLE_THINGSPEAK;
unsigned long THINGSPEAK_CHANNEL_ID = CFG_THINGSPEAK_CHANNEL_ID;
const char* THINGSPEAK_WRITE_API_KEY = CFG_THINGSPEAK_WRITE_API_KEY;

// Telegram
const bool ENABLE_TELEGRAM = CFG_ENABLE_TELEGRAM;
const char* TELEGRAM_BOT_TOKEN = CFG_TELEGRAM_BOT_TOKEN;
const char* TELEGRAM_CHAT_ID   = CFG_TELEGRAM_CHAT_ID;

// PHP/MySQL logging endpoint
const bool ENABLE_MYSQL_HTTP = CFG_ENABLE_MYSQL_HTTP;
const char* MYSQL_POST_URL = CFG_MYSQL_POST_URL;
const char* MYSQL_POST_API_KEY = CFG_MYSQL_POST_API_KEY;

// Cooling relay control endpoint
const char* CONTROL_API_URL = CFG_CONTROL_API_URL;
const char* CONTROL_API_KEY = CFG_CONTROL_API_KEY;
// Manual relay timer (used only in MANUAL mode)
const unsigned long MANUAL_RELAY_TIMER_MS = 10UL * 60UL * 1000UL; // 10 minutes

// ------------------------------------------------------------
// Monitoring thresholds and intervals
// ------------------------------------------------------------
const float TEMP_THRESHOLD_C = 35.0;
const float TEMP_RESET_C = 34.5;

const float HUMIDITY_THRESHOLD = 55.0;
const float HUMIDITY_RESET = 60.0;

// Air Quality Thresholds (MQ-135 Raw Values 0-1023)
const int AQ_MODERATE_RAW  = 250;  
const int AQ_POOR_RAW      = 350;  
const int AQ_UNHEALTHY_RAW = 500;
const int AQ_HAZARDOUS_RAW = 700;

const unsigned long SENSOR_INTERVAL_MS      = 3000;
const unsigned long SAFETY_INTERVAL_MS      = 1000;
const unsigned long LCD_INTERVAL_MS         = 1000;
const unsigned long LCD_PAGE_INTERVAL_MS    = 4000;
const unsigned long WIFI_RETRY_MS           = 10000;
const unsigned long STARTUP_WIFI_STABLE_MS  = 10000;
const unsigned long STARTUP_WIFI_STATUS_MS  = 500;
const unsigned long THINGSPEAK_INTERVAL_MS  = 20000;
const unsigned long MYSQL_INTERVAL_MS       = 300000;
const unsigned long CONTROL_POLL_INTERVAL_MS= 5000;
const unsigned long RELAY_REPORT_INTERVAL_MS= 10000;
const unsigned long HTTP_TIMEOUT_MS        = 2500;
const uint8_t TELEGRAM_MAX_BATCHES_PER_LOOP = 1;
const unsigned long TELEGRAM_POLL_MS        = 2000;

// Startup Telegram notice control
const bool ENABLE_STARTUP_TELEGRAM = true;
const unsigned long STARTUP_TELEGRAM_DELAY_MS = 60000;  // send boot notice only after 60s stable uptime

// LCD icon indexes
const uint8_t ICON_WIFI   = 0;
const uint8_t ICON_TEMP   = 1;
const uint8_t ICON_HUM    = 2;
const uint8_t ICON_ALARM  = 3;
const uint8_t ICON_OK     = 4;
const uint8_t ICON_FAIL   = 5;
const uint8_t ICON_COOL   = 6;
const uint8_t ICON_BOT    = 7;

const uint8_t HISTORY_SIZE = 24;

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(LCD_ADDRESS, LCD_COLUMNS, LCD_ROWS);
WiFiClient thingSpeakClient;
WiFiClient httpClient;
WiFiClientSecure telegramClient;
UniversalTelegramBot bot(TELEGRAM_BOT_TOKEN, telegramClient);

struct SensorData {
  float humidity = NAN;
  float tempC = NAN;
  float tempF = NAN;
  float heatIndexC = NAN;
  float heatIndexF = NAN;
  bool valid = false;
};

// Replaces older 3-tier SmokeLevel
enum AirQualityLevel {
  AQ_GOOD = 0,
  AQ_MODERATE = 1,
  AQ_POOR = 2,
  AQ_UNHEALTHY = 3,
  AQ_HAZARDOUS = 4
};

struct SafetyData {
  int MQ135Raw = 0;
  AirQualityLevel aqLevel = AQ_GOOD;
  bool flameDetected = false;
  bool safetyActive = false;
  bool valid = false;
};

SensorData currentData;
SafetyData safetyData;

float tempHistory[HISTORY_SIZE];
float humHistory[HISTORY_SIZE];
uint8_t historyIndex = 0;
uint8_t historyCount = 0;

bool alarmActive = false;
bool buzzerState = false;
bool thingSpeakOk = false;
bool mysqlOk = false;
bool controlApiOk = false;
bool relayState = false;
bool coolingAutoMode = true;
bool manualCoolingRequest = false;
bool manualModeActive = false;
bool manualForceOff = false;
bool manualTimerActive = false;

// Non-blocking buzzer control
bool buzzerPatternActive = false;
bool buzzerOutputState = false;

unsigned long buzzerLastToggleMs = 0;
unsigned long buzzerOnDurationMs = 80;
unsigned long buzzerOffDurationMs = 920;

// Dedicated 10-minute manual fan timer
const unsigned long FAN_ON_TIMER_DURATION_MS = 10UL * 60UL * 1000UL;
bool fanOnTimerActive = false;
unsigned long fanOnTimerStartMs = 0;
unsigned long fanOnTimerDurationMs = FAN_ON_TIMER_DURATION_MS;
const unsigned long MANUAL_FAN_TIMER_MS = 10UL * 60UL * 1000UL;
bool lastManualCoolingRequest = false;
bool lastTelegramSendOk = false;
bool telegramManualOverride = false;
bool manualTimerExpiredLatch = false;
bool startupTelegramSent = false;

unsigned long manualRelayTimerStartMs = 0;
unsigned long manualRelayTimerDurationMs = MANUAL_RELAY_TIMER_MS;
unsigned long bootMs = 0;
unsigned long lastSensorReadMs = 0;
unsigned long lastSafetyReadMs = 0;
unsigned long lastLcdUpdateMs = 0;
unsigned long lastLcdPageMs = 0;
unsigned long lastWiFiRetryMs = 0;
bool otaStarted = false;
unsigned long lastOTACheckMs = 0;
const unsigned long OTA_CHECK_INTERVAL_MS = 1000UL;
bool pendingReset = false;
unsigned long resetRequestMs = 0;
const unsigned long RESET_DELAY_MS = 3000UL;
bool telegramBootCleared = false;
unsigned long lastThingSpeakMs = 0;
unsigned long lastMySqlPostMs = 0;
unsigned long lastControlPollMs = 0;
unsigned long lastRelayReportMs = 0;
unsigned long lastBuzzerToggleMs = 0;
unsigned long lastTelegramPollMs = 0;
uint8_t lcdPage = 0;

String trimOrPad20(String text) {
  if (text.length() > LCD_COLUMNS) return text.substring(0, LCD_COLUMNS);
  while (text.length() < LCD_COLUMNS) text += " ";
  return text;
}

void lcdPrintLine(uint8_t row, const String& text) {
  lcd.setCursor(0, row);
  lcd.print(trimOrPad20(text));
}

String ipToString(IPAddress ip) {
  return String(ip[0]) + "." + String(ip[1]) + "." + String(ip[2]) + "." + String(ip[3]);
}

String formatDuration(unsigned long ms) {
  unsigned long totalSeconds = ms / 1000UL;

  unsigned int hours = totalSeconds / 3600UL;
  unsigned int minutes = (totalSeconds % 3600UL) / 60UL;
  unsigned int seconds = totalSeconds % 60UL;

  char buffer[12];
  snprintf(buffer, sizeof(buffer), "%02u:%02u:%02u", hours, minutes, seconds);

  return String(buffer);
}

// ------------------------------------------------------------
// Air Quality Functions
// ------------------------------------------------------------
AirQualityLevel classifyAirQuality(int raw) {
  if (raw >= AQ_HAZARDOUS_RAW) return AQ_HAZARDOUS;
  if (raw >= AQ_UNHEALTHY_RAW) return AQ_UNHEALTHY;
  if (raw >= AQ_POOR_RAW)      return AQ_POOR;
  if (raw >= AQ_MODERATE_RAW)  return AQ_MODERATE;
  return AQ_GOOD;
}

String aqLevelToString(AirQualityLevel level) {
  switch (level) {
    case AQ_HAZARDOUS: return "HAZARDOUS";
    case AQ_UNHEALTHY: return "UNHEALTHY";
    case AQ_POOR:      return "POOR";
    case AQ_MODERATE:  return "MODERATE";
    default:           return "GOOD";
  }
}

int aqLevelToValue(AirQualityLevel level) {
  return (int)level;
}

void createLcdIcons() {
  byte wifi[8]  = {B00000,B01110,B10001,B00100,B01010,B00000,B00100,B00000};
  byte temp[8]  = {B00100,B01010,B01010,B01110,B01110,B11111,B11111,B01110};
  byte hum[8]   = {B00100,B00100,B01010,B01010,B10001,B10001,B10001,B01110};
  byte alarm[8] = {B00100,B01110,B01110,B01110,B11111,B00100,B00000,B00100};
  byte ok[8]    = {B00000,B00001,B00011,B10110,B11100,B01000,B00000,B00000};
  byte fail[8]  = {B00000,B10001,B01010,B00100,B00100,B01010,B10001,B00000};
  byte cool[8]  = {B00100,B01010,B00100,B11111,B00100,B01010,B10001,B00000};
  byte botc[8]  = {B01110,B10001,B10101,B11111,B10101,B10001,B01110,B00100};

  lcd.createChar(ICON_WIFI, wifi);
  lcd.createChar(ICON_TEMP, temp);
  lcd.createChar(ICON_HUM, hum);
  lcd.createChar(ICON_ALARM, alarm);
  lcd.createChar(ICON_OK, ok);
  lcd.createChar(ICON_FAIL, fail);
  lcd.createChar(ICON_COOL, cool);
  lcd.createChar(ICON_BOT, botc);
}

void addHistorySample(float tempC, float humidity) {
  tempHistory[historyIndex] = tempC;
  humHistory[historyIndex] = humidity;
  historyIndex = (historyIndex + 1) % HISTORY_SIZE;
  if (historyCount < HISTORY_SIZE) historyCount++;
}

float getHistoryValue(const float arr[], uint8_t logicalIndex) {
  if (logicalIndex >= historyCount) return NAN;
  int physical = historyIndex - historyCount + logicalIndex;
  while (physical < 0) physical += HISTORY_SIZE;
  return arr[physical % HISTORY_SIZE];
}

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.println("Starting Wi-Fi connection...");
  WiFi.mode(WIFI_STA);
  WiFi.hostname("CatSU-NOC-Monitor");
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  // Non-blocking: do not wait here. ensureWiFiConnected() will retry by interval.
}

bool isWiFiReady() {
  IPAddress ip = WiFi.localIP();
  return WiFi.status() == WL_CONNECTED && (ip[0] != 0 || ip[1] != 0 || ip[2] != 0 || ip[3] != 0);
}

void waitForStableWiFiAtStartup() {
  Serial.println("Waiting for stable Wi-Fi before starting system...");
  lcd.clear();
  lcdPrintLine(0, "WiFi required");
  lcdPrintLine(1, "Connecting...");
  lcdPrintLine(2, "System paused");
  lcdPrintLine(3, "Please wait");

  connectWiFi();

  unsigned long stableSinceMs = 0;
  unsigned long lastRetryMs = millis();
  unsigned long lastStatusMs = 0;

  while (true) {
    unsigned long now = millis();
    bool wifiReady = isWiFiReady();

    if (wifiReady) {
      if (stableSinceMs == 0) {
        stableSinceMs = now;
        Serial.print("Wi-Fi connected. IP: ");
        Serial.println(WiFi.localIP());
      }

      unsigned long stableForMs = now - stableSinceMs;
      if (now - lastStatusMs >= STARTUP_WIFI_STATUS_MS) {
        lastStatusMs = now;
        lcdPrintLine(1, "Connected");
        lcdPrintLine(2, "Stabilizing WiFi");
        lcdPrintLine(3, String(stableForMs / 1000UL) + "/" + String(STARTUP_WIFI_STABLE_MS / 1000UL) + " sec");
      }

      if (stableForMs >= STARTUP_WIFI_STABLE_MS) {
        Serial.println("Wi-Fi connection is stable. Starting system...");
        lcd.clear();
        lcdPrintLine(0, "WiFi stable");
        lcdPrintLine(1, "IP:");
        lcdPrintLine(2, ipToString(WiFi.localIP()));
        lcdPrintLine(3, "Starting system...");
        delay(1500);
        lastWiFiRetryMs = now;
        return;
      }
    } else {
      if (stableSinceMs != 0) {
        Serial.println("Wi-Fi dropped during startup stability check. Waiting again...");
      }
      stableSinceMs = 0;

      if (now - lastStatusMs >= STARTUP_WIFI_STATUS_MS) {
        lastStatusMs = now;
        lcdPrintLine(1, "Connecting...");
        lcdPrintLine(2, "System paused");
        lcdPrintLine(3, "Retrying WiFi");
      }

      if (now - lastRetryMs >= WIFI_RETRY_MS) {
        lastRetryMs = now;
        connectWiFi();
      }
    }

    delay(50);
    yield();
  }
}

void ensureWiFiConnected() {
  if (WiFi.status() == WL_CONNECTED) return;

  if (millis() - lastWiFiRetryMs >= WIFI_RETRY_MS) {
    lastWiFiRetryMs = millis();
    Serial.println("Wi-Fi not connected. Retrying in background...");
    connectWiFi();
  }
}

bool readSensor() {
  float humidity = dht.readHumidity();
  float tempC = dht.readTemperature();
  float tempF = dht.readTemperature(true);
  if (isnan(humidity) || isnan(tempC) || isnan(tempF)) {
    currentData.valid = false;
    Serial.println("Failed to read from DHT22 sensor.");
    return false;
  }

  currentData.humidity = humidity;
  currentData.tempC = tempC;
  currentData.tempF = tempF;
  currentData.heatIndexC = dht.computeHeatIndex(tempC, humidity, false);
  currentData.heatIndexF = dht.computeHeatIndex(tempF, humidity, true);
  currentData.valid = true;

  addHistorySample(tempC, humidity);

  Serial.println("----------------------------------------");
  Serial.print("Humidity: "); Serial.print(currentData.humidity); Serial.println(" %");
  Serial.print("Temperature: "); Serial.print(currentData.tempC);
  Serial.print(" C / "); Serial.print(currentData.tempF); Serial.println(" F");
  Serial.print("Heat Index: "); Serial.print(currentData.heatIndexC); Serial.print(" C / "); Serial.print(currentData.heatIndexF); Serial.println(" F");
  return true;
}

void setRelayOutput(bool on) {
  relayState = on;
  uint8_t level = RELAY_ACTIVE_LOW ? (on ? LOW : HIGH) : (on ? HIGH : LOW);
  digitalWrite(RELAY_PIN, level);
}

void setBuzzerOutput(bool on) {
  buzzerState = on;
  uint8_t level = BUZZER_ACTIVE_LOW ? (on ? LOW : HIGH) : (on ? HIGH : LOW);
  digitalWrite(BUZZER_PIN, level);
}

unsigned long getManualRelayTimerRemainingMs() {
  return getFanOnTimerRemainingMs();
}

void startManualRelayTimer() {
  manualTimerActive = true;
  manualRelayTimerStartMs = millis();
  Serial.print("Manual relay timer started: ");
  Serial.print(manualRelayTimerDurationMs / 1000UL);
  Serial.println(" seconds");
}

void stopManualRelayTimer() {
  if (manualTimerActive) {
    Serial.println("Manual relay timer stopped.");
  }
  manualTimerActive = false;
}

unsigned long getFanOnTimerRemainingMs() {
  if (!fanOnTimerActive) {
    return 0;
  }

  unsigned long elapsedMs = millis() - fanOnTimerStartMs;

  if (elapsedMs >= fanOnTimerDurationMs) {
    return 0;
  }

  return fanOnTimerDurationMs - elapsedMs;
}

String getFanOnTimerRemainingText() {
  return formatDuration(getFanOnTimerRemainingMs());
}

void startFanOnTimer() {
  fanOnTimerDurationMs = FAN_ON_TIMER_DURATION_MS;
  fanOnTimerStartMs = millis();
  fanOnTimerActive = true;

  manualTimerActive = true;
  manualCoolingRequest = true;

  manualRelayTimerDurationMs = FAN_ON_TIMER_DURATION_MS;
  manualRelayTimerStartMs = fanOnTimerStartMs;
}

void stopFanOnTimer() {
  fanOnTimerActive = false;

  manualTimerActive = false;
  manualCoolingRequest = false;

  manualRelayTimerDurationMs = 0;
}

void updateManualRelayTimer() {
  if (!fanOnTimerActive) {
    manualTimerActive = false;
    return;
  }

  unsigned long elapsedMs = millis() - fanOnTimerStartMs;

  if (elapsedMs < fanOnTimerDurationMs) {
    manualTimerActive = true;
    return;
  }

  stopFanOnTimer();

  manualCoolingRequest = false;
  manualModeActive = true;
  manualForceOff = true;
  alarmActive = false;

  setRelayOutput(false);

  String msg = String("MANUAL FAN TIMER FINISHED ");
  msg += String("Fan turned OFF. ");
  msg += String("System remains in MANUAL mode. ");
  msg += String("Send /auto to resume automatic cooling.");

  sendTelegram(msg);

  Serial.println("Manual fan timer finished -> FAN OFF, MANUAL MODE LOCKED");
}

void applyCoolingControl() {
  if (manualModeActive) {
    if (manualForceOff) {
      setRelayOutput(false);
      return;
    }
  if (getEffectiveManualRequest()) {
  setRelayOutput(true);
  return;
}
    

    setRelayOutput(false);
    return;
  }

  setRelayOutput(alarmActive);
}

bool getEffectiveManualRequest() {
  return manualCoolingRequest || fanOnTimerActive || manualTimerActive;
}

String getCoolingModeText() {
  return manualModeActive ? "MANUAL" : "AUTO";
}

String getCoolingStateText() {
  if (manualModeActive) {
    if (manualForceOff) {
      return "FORCE OFF";
    }

    if (getEffectiveManualRequest()) {
  return "FORCE ON";
}

    return "MANUAL IDLE";
  }

  return alarmActive ? "AUTO ACTIVE" : "AUTO NORMAL";
}

String buildStatusMessage(const String& title) {
  String msg = title + "\n";

  if (currentData.valid) {
    msg += "Temp: " + String(currentData.tempC, 2) + " C\n";
    msg += "Humidity: " + String(currentData.humidity, 2) + " %\n";
    msg += "Heat Index: " + String(currentData.heatIndexC, 2) + " C\n";
  } else {
    msg += "Temp: N/A\n";
    msg += "Humidity: N/A\n";
    msg += "Heat Index: N/A\n";
  }

  msg += "Temp Alarm: " + String(alarmActive ? "ACTIVE" : "NORMAL") + "\n";

  if (safetyData.valid) {
    msg += "MQ-135 Raw Value: " + String(safetyData.MQ135Raw) + "\n";
    msg += "Air Quality: " + aqLevelToString(safetyData.aqLevel) + "\n";
  } else {
    msg += "MQ-135 Raw Value: N/A\n";
    msg += "Air Quality: N/A\n";
  }

  msg += "HL-01 Flame: " + String(safetyData.flameDetected ? "DETECTED" : "NORMAL") + "\n";
  msg += "Safety Warning: " + String(safetyData.safetyActive ? "ACTIVE" : "NORMAL") + "\n";
  msg += "Cooling Mode: " + getCoolingModeText() + "\n";
  msg += "Cooling State: " + getCoolingStateText() + "\n";

  if (manualModeActive) {
  bool effectiveManualRequest = getEffectiveManualRequest();

  msg += "Manual Request: " + String(effectiveManualRequest ? "ON" : "OFF") + "\n";
  msg += "Manual Command: " + String(manualCoolingRequest ? "ON" : "OFF") + "\n";
  msg += "Manual Force Off: " + String(manualForceOff ? "ON" : "OFF") + "\n";
  msg += "Manual Timer: " + String((fanOnTimerActive || manualTimerActive) ? "RUNNING" : "IDLE") + "\n";
  msg += "Remaining: " + getFanOnTimerRemainingText() + "\n";
  }

  msg += "Relay: " + String(relayState ? "ON" : "OFF") + "\n";
  msg += "Wi-Fi: " + String(WiFi.status() == WL_CONNECTED ? "Connected" : "Disconnected") + "\n";
  msg += "IP: " + String(WiFi.status() == WL_CONNECTED ? ipToString(WiFi.localIP()) : "N/A");

  return msg;
}


bool isCriticalBuzzerCondition() {
  return safetyData.flameDetected || (safetyData.aqLevel >= AQ_UNHEALTHY);
}

bool isWarningBuzzerCondition() {
  return alarmActive || (safetyData.aqLevel == AQ_MODERATE) || (safetyData.aqLevel == AQ_POOR);
}

String getBuzzerModeText() {
  if (isCriticalBuzzerCondition()) return "CRITICAL - CONTINUOUS";
  if (isWarningBuzzerCondition()) return "WARNING - INTERMITTENT";
  return "OFF";
}

String buildSafetyMessage(const String& title) {
  String msg = title + "\n";
  msg += "MQ-135 Raw Value: " + String(safetyData.MQ135Raw) + " / 1023\n";
  msg += "Air Quality: " + aqLevelToString(safetyData.aqLevel) + "\n";
  msg += "Thresholds: MODERATE>=" + String(AQ_MODERATE_RAW) + " | POOR>=" + String(AQ_POOR_RAW) + " | UNHEALTHY>=" + String(AQ_UNHEALTHY_RAW) + " | HAZARDOUS>=" + String(AQ_HAZARDOUS_RAW) + "\n";
  msg += "HL-01 Flame: " + String(safetyData.flameDetected ? "DETECTED" : "NORMAL") + "\n";
  msg += "Buzzer: " + getBuzzerModeText() + "\n";
  msg += "Cooling FAN: " + String(relayState ? "ON" : "OFF") + " (temperature/manual only)";
  return msg;
}

String buildCloudMessage() {
  String msg = "Cloud Status\n";
  msg += "WiFi: " + String(WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED") + "\n";

  if (WiFi.status() == WL_CONNECTED) {
    msg += "SSID: " + String(WIFI_SSID) + "\n";
    msg += "IP: " + ipToString(WiFi.localIP()) + "\n";
    msg += "RSSI: " + String(WiFi.RSSI()) + " dBm\n";
  }

  msg += "ThingSpeak: " + String(ENABLE_THINGSPEAK ? (thingSpeakOk ? "OK" : "WAIT/ERR") : "DISABLED") + "\n";
  msg += "MySQL HTTP: " + String(ENABLE_MYSQL_HTTP ? (mysqlOk ? "OK" : "WAIT/ERR") : "DISABLED") + "\n";
  msg += "Control API: " + String(controlApiOk ? "OK" : "WAIT/ERR") + "\n";
  msg += "Telegram: " + String(ENABLE_TELEGRAM ? (lastTelegramSendOk ? "OK" : "IDLE/ERR") : "DISABLED") + "\n";

  msg += "Last ThingSpeak: " + formatDuration(millis() - lastThingSpeakMs) + " ago\n";
  msg += "Last MySQL POST: " + formatDuration(millis() - lastMySqlPostMs) + " ago\n";
  msg += "Last Control Poll: " + formatDuration(millis() - lastControlPollMs) + " ago\n";
  msg += "Last Relay Report: " + formatDuration(millis() - lastRelayReportMs) + " ago\n";

  msg += "Relay/FAN: " + String(relayState ? "ON" : "OFF") + "\n";
  msg += "Mode: " + getCoolingModeText() + "\n";
  msg += "Uptime: " + formatDuration(millis() - bootMs);

  return msg;
}

void sendTelegram(const String& message) {
  if (!ENABLE_TELEGRAM) return;
  if (strlen(TELEGRAM_BOT_TOKEN) == 0 || strlen(TELEGRAM_CHAT_ID) == 0) return;
  if (WiFi.status() != WL_CONNECTED) return;
  lastTelegramSendOk = bot.sendMessage(TELEGRAM_CHAT_ID, message, "");
  Serial.println(lastTelegramSendOk ? "Telegram message sent." : "Telegram send failed.");
}

void checkStartupTelegram() {
  if (!ENABLE_STARTUP_TELEGRAM) return;
  if (startupTelegramSent) return;
  if (WiFi.status() != WL_CONNECTED) return;
  if (millis() - bootMs < STARTUP_TELEGRAM_DELAY_MS) return;

  String bootMsg = "ESP8266 monitor started.\n";
  bootMsg += "DHT22 + HL-01 flame + MQ-135 analog air quality monitoring enabled.\n";
  bootMsg += "Device IP: " + ipToString(WiFi.localIP()) + "\n";
  bootMsg += "Reset reason: " + ESP.getResetReason() + "\n";
  bootMsg += "Uptime before notice: " + formatDuration(millis());

  sendTelegram(bootMsg);
  startupTelegramSent = true;
}

void readSafetySensors() {
  int previousMQ135Raw = safetyData.MQ135Raw;
  AirQualityLevel previousAQLevel = safetyData.aqLevel;
  bool previousFlameDetected = safetyData.flameDetected;
  bool previousSafetyActive = safetyData.safetyActive;

  int MQ135Raw = analogRead(MQ135_ANALOG_PIN);
  bool flameDetected = (digitalRead(FLAME_PIN) == (FLAME_ACTIVE_LOW ? LOW : HIGH));
  AirQualityLevel aqLevel = classifyAirQuality(MQ135Raw);

  safetyData.MQ135Raw = MQ135Raw;
  safetyData.aqLevel = aqLevel;
  safetyData.flameDetected = flameDetected;
  safetyData.safetyActive = flameDetected || (aqLevel != AQ_GOOD);
  safetyData.valid = true;

  Serial.print("MQ-135 Raw: "); Serial.print(safetyData.MQ135Raw);
  Serial.print(" | AQ Level: "); Serial.print(aqLevelToString(safetyData.aqLevel));
  Serial.print(" | HL-01 Flame: "); Serial.println(safetyData.flameDetected ? "DETECTED" : "NORMAL");

  bool changed = (previousAQLevel != safetyData.aqLevel) ||
                 (previousFlameDetected != safetyData.flameDetected) ||
                 (previousSafetyActive != safetyData.safetyActive) ||
                 (abs(MQ135Raw - previousMQ135Raw) >= 80 && safetyData.safetyActive);

  if (!changed) return;

  if (safetyData.safetyActive) {
    if (safetyData.flameDetected || safetyData.aqLevel >= AQ_UNHEALTHY) {
      sendTelegram(buildSafetyMessage("CRITICAL SAFETY WARNING: Flame or Unhealthy/Hazardous Air Detected"));
    } else {
      sendTelegram(buildSafetyMessage("AIR QUALITY ADVISORY: Air quality dropped to " + aqLevelToString(safetyData.aqLevel)));
    }
  } else if (previousSafetyActive) {
    sendTelegram(buildSafetyMessage("SAFETY RECOVERY: Air Quality and Flame returned to normal"));
  }
}

void updateBuzzerNonBlocking() {
  if (!buzzerPatternActive) {
    if (buzzerOutputState) {
      buzzerOutputState = false;
      setBuzzerOutput(false);
    }
    return;
  }

  unsigned long now = millis();

  if (buzzerOutputState) {
    if (now - buzzerLastToggleMs >= buzzerOnDurationMs) {
      buzzerOutputState = false;
      buzzerLastToggleMs = now;
      setBuzzerOutput(false);
    }
  } else {
    if (now - buzzerLastToggleMs >= buzzerOffDurationMs) {
      buzzerOutputState = true;
      buzzerLastToggleMs = now;
      setBuzzerOutput(true);
    }
  }
}

void handleAlarmState() {

  // MANUAL MODE LOCK:
  // /fan_on and /fan_off override AUTO until /auto is received.
  if (manualModeActive) {
    if (manualForceOff) {
      alarmActive = false;
    }

    buzzerPatternActive = alarmActive || safetyData.safetyActive;
    applyCoolingControl();
    return;
  }

  if (!currentData.valid) {
    alarmActive = false;
    buzzerPatternActive = safetyData.safetyActive;
    applyCoolingControl();
    return;
  }

  bool newAlarmState = alarmActive;

  // AUTO activate: temperature OR humidity reaches threshold
  if (!alarmActive &&
      (currentData.tempC >= TEMP_THRESHOLD_C &&
       currentData.humidity >= HUMIDITY_THRESHOLD)) {
    newAlarmState = true;
  }

  // AUTO reset: BOTH temperature and humidity must return to safe levels
  if (alarmActive &&
      (currentData.tempC <= TEMP_RESET_C &&
       currentData.humidity <= HUMIDITY_RESET)) {
    newAlarmState = false;
  }

  if (newAlarmState != alarmActive) {
    alarmActive = newAlarmState;

    // Update relayState and physical relay BEFORE sending status message.
    applyCoolingControl();

    Serial.print("AUTO Cooling State: ");
    Serial.println(alarmActive ? "ON" : "OFF");

    if (alarmActive) {
      sendTelegram(buildStatusMessage("AUTO COOLING ACTIVATED"));
    } else {
      sendTelegram(buildStatusMessage("AUTO COOLING DEACTIVATED"));
    }
  } else {
    applyCoolingControl();
  }

  buzzerPatternActive = alarmActive || safetyData.safetyActive;
}


void lcdHeaderIconText(uint8_t row, uint8_t icon, const String& text) {
  lcd.setCursor(0, row);
  lcd.write(icon);
  lcd.print(trimOrPad20(" " + text).substring(0, 19));
}

void lcdValueIcon(uint8_t row, uint8_t icon, const String& label, const String& value) {
  lcd.setCursor(0, row);
  lcd.write(icon);
  lcd.print(trimOrPad20(" " + label + value).substring(0, 19));
}

void renderLcdOverviewPage() {
  lcdHeaderIconText(0, ICON_TEMP, "Overview");
  if (currentData.valid) {
    String stateText = safetyData.safetyActive ? "SAFETY WARN" : (alarmActive ? "TEMP ALARM" : "NORMAL");
    lcdValueIcon(1, ICON_TEMP, "Temp: ", String(currentData.tempC, 1) + "C");
    lcdValueIcon(2, ICON_HUM,  "Hum : ", String(currentData.humidity, 1) + "%");
    lcdValueIcon(3, ICON_ALARM, "State: ", stateText);
  } else {
    lcdValueIcon(1, ICON_FAIL, "Sensor: ", "READ FAIL");
    lcdValueIcon(2, ICON_HUM, "Check: ", "DHT22 wiring");
    lcdValueIcon(3, ICON_ALARM, "Safety:", safetyData.safetyActive ? "WARNING" : "NORMAL");
  }
}

void renderLcdCoolingPage() {
  lcdHeaderIconText(0, ICON_COOL, "Cooling");
  lcdValueIcon(1, ICON_COOL, "Mode: ", getCoolingModeText());
  lcdValueIcon(2, relayState ? ICON_OK : ICON_FAIL, "Relay:", relayState ? "ON" : "OFF");

  if (!manualModeActive) {
    lcdValueIcon(3, ICON_ALARM, "Alarm:", alarmActive ? "ACTIVE" : "NORMAL");
  } else {
    if (fanOnTimerActive) {
      lcdValueIcon(3, ICON_COOL, "Timer:", getFanOnTimerRemainingText());
    } else {
      lcdValueIcon(3, ICON_COOL, "Manual:", getCoolingStateText());
    }
  }
}



void renderLcdCloudPage() {
  lcdHeaderIconText(0, ICON_WIFI, "Cloud");
  lcdValueIcon(1, thingSpeakOk ? ICON_OK : ICON_FAIL, "ThingSpeak:", thingSpeakOk ? "OK" : "WAIT");
  lcdValueIcon(2, mysqlOk ? ICON_OK : ICON_FAIL, "MySQL:", mysqlOk ? "OK" : "WAIT");
  lcdValueIcon(3, controlApiOk ? ICON_OK : ICON_FAIL, "Control:", controlApiOk ? "OK" : "WAIT");
}

void renderLcdNetworkPage() {
  lcdHeaderIconText(0, ICON_WIFI, "Network");
  lcdValueIcon(1, ICON_WIFI, "WiFi: ", WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DOWN");
  lcdValueIcon(2, ICON_WIFI, "IP: ", WiFi.status() == WL_CONNECTED ? ipToString(WiFi.localIP()) : "N/A");
  String rssi = (WiFi.status() == WL_CONNECTED) ? String(WiFi.RSSI()) + " dBm" : "--";
  lcdValueIcon(3, ICON_WIFI, "RSSI:", rssi);
}

void renderLcdSafetyPage() {
  lcdHeaderIconText(0, ICON_ALARM, "Air Quality & Fire");
  lcdValueIcon(1, ICON_ALARM, "MQ135 Raw:", String(safetyData.valid ? safetyData.MQ135Raw : -1));
  lcdValueIcon(2, ICON_ALARM, "AQI:  ", aqLevelToString(safetyData.aqLevel));
  lcdValueIcon(3, safetyData.flameDetected ? ICON_FAIL : ICON_OK, "Flame:", safetyData.flameDetected ? "DETECTED" : "NORMAL");
}

void updateLCD() {
  if (millis() - lastLcdPageMs >= LCD_PAGE_INTERVAL_MS) {
    lastLcdPageMs = millis();
    lcdPage = (lcdPage + 1) % 5;
  }

  switch (lcdPage) {
    case 0: renderLcdOverviewPage(); break;
    case 1: renderLcdCoolingPage(); break;
    case 2: renderLcdCloudPage(); break;
    case 3: renderLcdNetworkPage(); break;
    default: renderLcdSafetyPage(); break;
  }
}

void sendToThingSpeak() {
  if (!ENABLE_THINGSPEAK) return;
  if (WiFi.status() != WL_CONNECTED) return;
  if (THINGSPEAK_CHANNEL_ID == 0 || strlen(THINGSPEAK_WRITE_API_KEY) == 0) return;

  if (currentData.valid) {
    ThingSpeak.setField(1, currentData.tempC);
    ThingSpeak.setField(2, currentData.humidity);
    ThingSpeak.setField(3, currentData.heatIndexC);
  }
  ThingSpeak.setField(4, alarmActive ? 1 : 0);
  ThingSpeak.setField(5, safetyData.valid ? safetyData.MQ135Raw : 0);
  ThingSpeak.setField(6, aqLevelToValue(safetyData.aqLevel));
  ThingSpeak.setField(7, safetyData.flameDetected ? 1 : 0);
  ThingSpeak.setField(8, safetyData.safetyActive ? 1 : 0);

  int responseCode = ThingSpeak.writeFields(THINGSPEAK_CHANNEL_ID, THINGSPEAK_WRITE_API_KEY);
  thingSpeakOk = (responseCode == 200);

  Serial.print("ThingSpeak response: ");
  Serial.println(responseCode);
}

void sendToMySqlHttp() {
  if (!ENABLE_MYSQL_HTTP || !currentData.valid) return;
  if (WiFi.status() != WL_CONNECTED) return;
  if (strlen(MYSQL_POST_URL) == 0) return;

  HTTPClient http;
  http.begin(httpClient, MYSQL_POST_URL);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  
  // Note: Keep variable names "smoke_level" to ensure PHP backend compatibility
  String postData = "api_key=" + String(MYSQL_POST_API_KEY) +
                    "&device=ESP8266_DHT22_HL01_MQ135" +
                    "&temp_c=" + String(currentData.tempC, 2) +
                    "&humidity=" + String(currentData.humidity, 2) +
                    "&heat_index_c=" + String(currentData.heatIndexC, 2) +
                    "&alarm=" + String(alarmActive ? 1 : 0) +
                    "&MQ135_raw=" + String(safetyData.MQ135Raw) +
                    "&smoke_level=" + aqLevelToString(safetyData.aqLevel) +
                    "&smoke_level_value=" + String(aqLevelToValue(safetyData.aqLevel)) +
                    "&flame_detected=" + String(safetyData.flameDetected ? 1 : 0) +
                    "&safety_alarm=" + String(safetyData.safetyActive ? 1 : 0);
                    
  int httpCode = http.POST(postData);
  mysqlOk = (httpCode > 0 && httpCode < 300);

  Serial.print("MySQL HTTP response: ");
  Serial.println(httpCode);
  if (httpCode > 0) Serial.println(http.getString());
  http.end();
}

void pollCoolingControl() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (strlen(CONTROL_API_URL) == 0 || strlen(CONTROL_API_KEY) == 0) return;

  HTTPClient http;
  String url = String(CONTROL_API_URL) + "?api_key=" + String(CONTROL_API_KEY);
  http.begin(httpClient, url);
  http.setTimeout(HTTP_TIMEOUT_MS);

  int httpCode = http.GET();
  controlApiOk = (httpCode == 200);
  if (httpCode == 200) {
    String payload = http.getString();
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, payload);
    
    if (!err && doc["ok"] == true) {
      if (alarmActive) {
        coolingAutoMode = true;
        manualCoolingRequest = false;
        lastManualCoolingRequest = false;
        telegramManualOverride = false;
        manualTimerExpiredLatch = false;
        stopManualRelayTimer();
        applyCoolingControl();
        Serial.println("AUTO alarm active. Manual/dashboard cooling request ignored.");
        http.end();
        return;
      }

      // TELEGRAM MANUAL LOCK:
      // /fan_on and /fan_off must not be overridden by dashboard/API polling.
      // Only /auto is allowed to clear this lock.
      if (telegramManualOverride && manualModeActive) {
        Serial.println("Telegram manual lock active. Skipping remote cooling control update.");
        applyCoolingControl();
        http.end();
        return;
      }

      String mode = doc["mode"] | "AUTO";
      bool remoteManualMode = (mode == "MANUAL");
      bool remoteManualOn = ((int)(doc["manual_state"] | 0) == 1);

      if (!remoteManualMode || !remoteManualOn) {
        manualTimerExpiredLatch = false;
      }

      if (manualTimerExpiredLatch && remoteManualMode && remoteManualOn) {
        coolingAutoMode = true;
        manualCoolingRequest = false;
        lastManualCoolingRequest = false;
        stopManualRelayTimer();
        applyCoolingControl();
        Serial.println("Ignoring stale manual ON after timer expiry. Send dashboard OFF/AUTO first.");
        http.end();
        return;
      }

      coolingAutoMode = !remoteManualMode;
    manualModeActive = remoteManualMode;
    manualCoolingRequest = remoteManualOn;
    manualForceOff = (remoteManualMode && !remoteManualOn);
      
      if (doc.containsKey("manual_timer_sec")) {
        unsigned long manualTimerSec = doc["manual_timer_sec"] | 0;
        if (manualTimerSec > 0) {
          manualRelayTimerDurationMs = manualTimerSec * 1000UL;
        }
      }

      if (coolingAutoMode) {
        stopManualRelayTimer();
        lastManualCoolingRequest = false;
      } else {
        if (manualCoolingRequest && !lastManualCoolingRequest) {
          startManualRelayTimer();
          applyCoolingControl();
          sendTelegram(buildStatusMessage("MANUAL COOLING ACTIVATED: Relay ON"));
        } else if (!manualCoolingRequest && lastManualCoolingRequest) {
          stopManualRelayTimer();
        }
        lastManualCoolingRequest = manualCoolingRequest;
      }

      applyCoolingControl();
    } else {
      controlApiOk = false;
      Serial.println("Cooling control JSON parse failed.");
    }
  } else {
    Serial.print("Cooling control GET failed: ");
    Serial.println(httpCode);
  }

  http.end();
}

void reportRelayState() {
  if (WiFi.status() != WL_CONNECTED) return;
  if (strlen(CONTROL_API_URL) == 0 || strlen(CONTROL_API_KEY) == 0) return;

  HTTPClient http;
  http.begin(httpClient, CONTROL_API_URL);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");

  String postData = "api_key=" + String(CONTROL_API_KEY) +
                    "&relay_state=" + String(relayState ? 1 : 0);
  int httpCode = http.POST(postData);
  if (httpCode > 0) {
    controlApiOk = (httpCode < 300);
  }

  Serial.print("Relay report response: ");
  Serial.println(httpCode);
  http.end();
}

void telegramManualFanOn() {
  if (alarmActive) {
    manualCoolingRequest = false;
    lastManualCoolingRequest = false;
    telegramManualOverride = false;
    stopManualRelayTimer();
    coolingAutoMode = true;
    applyCoolingControl();
    sendTelegram(buildStatusMessage("AUTO ALARM ACTIVE: /fan_on ignored. Fan follows alarm control."));
    return;
  }

  manualTimerExpiredLatch = false;
  coolingAutoMode = false;
  manualCoolingRequest = true;
  lastManualCoolingRequest = true;
  telegramManualOverride = true;

  startManualRelayTimer();
  applyCoolingControl();

  sendTelegram(buildStatusMessage("MANUAL FAN ON: Cooling fan activated by Telegram"));
}

void telegramManualFanOff() {
  manualModeActive = true;
  manualForceOff = true;
  alarmActive = false;
  manualCoolingRequest = false;
  lastManualCoolingRequest = false;

  // Keep Telegram manual lock active so dashboard/API cannot override /fan_off.
  // Only /auto can release this lock.
  telegramManualOverride = true;
  manualTimerExpiredLatch = false;
  coolingAutoMode = false;

  stopManualRelayTimer();
  stopFanOnTimer();

  applyCoolingControl();
  sendTelegram(buildStatusMessage("MANUAL FAN OFF: Fan forced OFF. AUTO locked until /auto."));
}

void telegramReturnToAutoMode() {
  manualCoolingRequest = false;
  lastManualCoolingRequest = false;
  telegramManualOverride = false;
  manualTimerExpiredLatch = false;

  stopManualRelayTimer();
  coolingAutoMode = true;
  applyCoolingControl();

  sendTelegram(buildStatusMessage("AUTO MODE ENABLED: Fan now follows alarm condition"));
}

String buildFanStatusMessage() {
  String msg = "Cooling FAN Status\n";
  msg += "Mode: " + getCoolingModeText() + "\n";
  msg += "State: " + getCoolingStateText() + "\n";
  msg += "Telegram Override: " + String(manualModeActive ? "YES" : "NO") + "\n";
  msg += "Manual Request: " + String(manualCoolingRequest ? "ON" : "OFF") + "\n";
  msg += "Manual Force Off: " + String(manualForceOff ? "ON" : "OFF") + "\n";
  msg += "Manual Timer: " + String(fanOnTimerActive ? "RUNNING" : "IDLE") + "\n";
  msg += "Remaining: " + getFanOnTimerRemainingText() + "\n";
  msg += "Temp Alarm: " + String(alarmActive ? "ACTIVE" : "NORMAL") + "\n";
  msg += "Safety Warning: " + String(safetyData.safetyActive ? "ACTIVE" : "NORMAL") + "\n";
  msg += "Relay/FAN: " + String(relayState ? "ON" : "OFF");
  return msg;
}



void acknowledgeTelegramBeforeRestart() {
  if (!ENABLE_TELEGRAM) return;
  if (WiFi.status() != WL_CONNECTED) return;

  // Non-blocking acknowledgement check only.
  bot.getUpdates(bot.last_message_received + 1);
}

void clearOldTelegramUpdatesAtBoot() {
  if (!ENABLE_TELEGRAM) return;
  if (WiFi.status() != WL_CONNECTED) return;

  // Non-blocking: clear only one batch at boot.
  bot.getUpdates(bot.last_message_received + 1);
}


void clearTelegramBootUpdatesTask() {
  if (!ENABLE_TELEGRAM) return;
  if (telegramBootCleared) return;
  if (WiFi.status() != WL_CONNECTED) return;

  // Clear one pending Telegram batch after Wi-Fi connects.
  // This prevents replaying an old /reset command after reboot.
  int numNewMessages = bot.getUpdates(bot.last_message_received + 1);
  if (numNewMessages >= 0) {
    telegramBootCleared = true;
    Serial.println("Telegram boot updates cleared.");
  }
}

void handlePendingResetTask() {
  if (!pendingReset) return;

  if (millis() - resetRequestMs >= RESET_DELAY_MS) {
    Serial.println("Restarting ESP8266 now...");
    setBuzzerOutput(false);
    yield();
    ESP.restart();
  }
}

void processTelegramCommand(const String& chatId, const String& rawText) {
  String text = rawText;
  text.trim();
  text.toLowerCase();

  if (text == "/start" || text == "/help") {
    String msg = "Available Commands\n";
    msg += "/status - Full system status\n";
    msg += "/cloud - Cloud/WiFi/API status\n";
    msg += "/cooling - Cooling status\n";
    msg += "/fan_on - Override AUTO and turn fan ON for 10 minutes\n";
    msg += "/fan_off - Manual fan OFF and lock AUTO\n";
    msg += "/fan_status - Fan status\n";
    msg += "/auto - Resume AUTO mode\n";
    msg += "/reset or /reboot - Restart device";
    bot.sendMessage(chatId, msg, "");
    return;
  }

  if (text == "/status") {
    bot.sendMessage(chatId, buildStatusMessage("Current System Status"), "");
    return;
  }

  if (text == "/cloud" || text == "/cloud_status" || text == "/cloud status") {
    bot.sendMessage(chatId, buildCloudMessage(), "");
    return;
  }

if (text == "/cooling") {
    bool effectiveManualRequest = getEffectiveManualRequest();

    String msg = "Cooling mode: " + getCoolingModeText();
    msg += "\nCooling state: " + getCoolingStateText();
    msg += "\nManual request: " + String(effectiveManualRequest ? "ON" : "OFF");
    msg += "\nManual force off: " + String(manualForceOff ? "ON" : "OFF");
    msg += "\nManual timer: " + String(fanOnTimerActive ? "RUNNING" : "IDLE");
    msg += "\nRemaining: " + getFanOnTimerRemainingText();
    msg += "\nRelay: " + String(relayState ? "ON" : "OFF");

    bot.sendMessage(chatId, msg, "");
    return;
  }

  if (text == "/fan_on" || text == "/fan on" || text == "fan_on") {

    // Do not override/restart the same Telegram command if fan is already ON.
    if (manualModeActive && !manualForceOff && manualCoolingRequest && relayState) {
      String msg = "Fan is already ON.\n";
      msg += "Duplicate /fan_on ignored. Timer was not restarted.\n";
      msg += "Mode: MANUAL\n";
      msg += "Manual Request: ON\n";
      msg += "Timer: " + String(fanOnTimerActive ? "RUNNING" : "IDLE") + "\n";
      msg += "Remaining: " + getFanOnTimerRemainingText() + "\n";
      msg += "Relay: ON";
      bot.sendMessage(chatId, msg, "");
      return;
    }

    bool wasAutoActive = (!manualModeActive && alarmActive);

    manualModeActive = true;
    manualForceOff = false;
    manualCoolingRequest = true;

    // Lock dashboard/API AUTO updates while Telegram /fan_on timer is active.
    coolingAutoMode = false;
    telegramManualOverride = true;
    lastManualCoolingRequest = true;
    manualTimerExpiredLatch = false;

    alarmActive = false;

    startFanOnTimer();
    applyCoolingControl();

    String msg = "MANUAL MODE ACTIVE\n";
    if (wasAutoActive) {
      msg += "AUTO cooling overridden by /fan_on.\n";
    }
    msg += "Fan FORCED ON\n";
    msg += "Manual Request: ON\n";
    msg += "Manual fan timer started: 10 minutes\n";
    msg += "Remaining: " + getFanOnTimerRemainingText() + "\n";
    msg += "AUTO is locked until /auto command.";
    bot.sendMessage(chatId, msg, "");

    Serial.println("MANUAL MODE -> /fan_on OVERRIDES AUTO WITH 10-MIN TIMER");
    return;
  }

  if (text == "/fan_off" || text == "/fan off" || text == "fan_off") {

    // Do not override/re-send the same Telegram command if fan is already OFF.
    if (manualModeActive && manualForceOff && !manualCoolingRequest && !relayState) {
      String msg = "Fan is already OFF.\n";
      msg += "Duplicate /fan_off ignored.\n";
      msg += "Mode: MANUAL\n";
      msg += "Manual Request: OFF\n";
      msg += "Relay: OFF\n";
      msg += "AUTO is locked until /auto command.";
      bot.sendMessage(chatId, msg, "");
      return;
    }

    manualModeActive = true;
    manualForceOff = true;
    alarmActive = false;
    manualCoolingRequest = false;

    // Keep Telegram manual lock active so dashboard/API cannot override /fan_off.
    // Only /auto can release this lock.
    telegramManualOverride = true;
    lastManualCoolingRequest = false;
    manualTimerExpiredLatch = false;

    stopFanOnTimer();

    applyCoolingControl();

    String msg = "MANUAL MODE ACTIVE\n";
    msg += "Fan FORCED OFF\n";
    msg += "AUTO mode is locked until /auto command.";
    bot.sendMessage(chatId, msg, "");

    Serial.println("MANUAL MODE -> FAN FORCE OFF");
    return;
  }

  if (text == "/fan_status") {
    bot.sendMessage(chatId, buildFanStatusMessage(), "");
    return;
  }

  if (text == "/auto" || text == "auto") {
    manualModeActive = false;
    manualForceOff = false;
    manualCoolingRequest = false;

    telegramManualOverride = false;
    lastManualCoolingRequest = false;
    manualTimerExpiredLatch = false;
    coolingAutoMode = true;

    stopFanOnTimer();
    alarmActive = false;
    applyCoolingControl();

    bot.sendMessage(chatId, "AUTO MODE RESTORED.", "");
    Serial.println("AUTO MODE RESTORED");
    return;
  }

  if (text == "/reset" || text == "/reboot") {
    if (!pendingReset) {
      // Advance Telegram offset before scheduling reboot to avoid command replay.
      bot.getUpdates(bot.last_message_received + 1);

      pendingReset = true;
      resetRequestMs = millis();

      bot.sendMessage(chatId, "Device restart requested. Restarting in 3 seconds...", "");
      Serial.println("Reset command received. Restart scheduled.");
    } else {
      bot.sendMessage(chatId, "Restart already scheduled.", "");
    }
    return;
  }

  bot.sendMessage(chatId, "Unknown command. Send /help for command list.", "");
}

void handleTelegramMessages(int numNewMessages) {
  for (int i = 0; i < numNewMessages; i++) {
    String chatId = bot.messages[i].chat_id;
    String text = bot.messages[i].text;
    text.trim();
    Serial.print("Telegram command: ");
    Serial.println(text);
    processTelegramCommand(chatId, text);
  }
}

void pollTelegram() {
  if (!ENABLE_TELEGRAM) return;
  if (WiFi.status() != WL_CONNECTED) return;

  // Process only a limited number of Telegram batches per loop cycle.
  // This prevents Telegram polling from blocking sensors, relay control, OTA, LCD, and buzzer.
  for (uint8_t batch = 0; batch < TELEGRAM_MAX_BATCHES_PER_LOOP; batch++) {
    int numNewMessages = bot.getUpdates(bot.last_message_received + 1);
    if (!numNewMessages) break;
    handleTelegramMessages(numNewMessages);
    yield();
  }
}


void handleOTATask() {
  unsigned long now = millis();

  // Start/restart OTA only after Wi-Fi is connected.
  // This avoids starting ArduinoOTA before the ESP8266 has an IP address.
  if (now - lastOTACheckMs >= OTA_CHECK_INTERVAL_MS) {
    lastOTACheckMs = now;

    if (!otaStarted && WiFi.status() == WL_CONNECTED) {
      ArduinoOTA.setHostname("CatSU-NOC-Monitor");
      ArduinoOTA.setPasswordHash("3b712de48137572f3849aabd5666a4e3");
      ArduinoOTA.onStart([]() {
        Serial.println("OTA Update Started");
        setBuzzerOutput(true);
      });

      ArduinoOTA.onEnd([]() {
        Serial.println("OTA Update Complete");
        setBuzzerOutput(false);
      });

      ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
        Serial.printf("OTA Progress: %u%%\r", (progress * 100) / total);
      });

      ArduinoOTA.onError([](ota_error_t error) {
        Serial.printf("OTA Error[%u]: ", error);
        setBuzzerOutput(false);
      });

      ArduinoOTA.begin();
      otaStarted = true;

      Serial.println("OTA Ready");
      Serial.print("OTA Hostname: ");
      Serial.println("CatSU-NOC-Monitor");
      Serial.print("OTA IP: ");
      Serial.println(WiFi.localIP());
    }

    if (otaStarted && WiFi.status() != WL_CONNECTED) {
      otaStarted = false;
      Serial.println("OTA stopped: Wi-Fi disconnected");
    }
  }

  // Must be called as often as possible after OTA starts.
  if (otaStarted && WiFi.status() == WL_CONNECTED) {
    ArduinoOTA.handle();
  }
}

void setup() {
  Serial.begin(115200);
  bootMs = millis();

  Serial.println();
  Serial.println("========== ESP8266 BOOT ==========");
  Serial.print("Reset reason: ");
  Serial.println(ESP.getResetReason());
  Serial.print("Reset info: ");
  Serial.println(ESP.getResetInfo());
  Serial.println("==================================");

  pinMode(BUZZER_PIN, OUTPUT);
  setBuzzerOutput(false);

  pinMode(RELAY_PIN, OUTPUT);
  setRelayOutput(false);

  pinMode(FLAME_PIN, INPUT);

  Wire.begin(D2, D1);
  lcd.init();
  lcd.backlight();
  createLcdIcons();
  lcd.clear();
  lcdPrintLine(0, "ESP8266 Monitor");
  lcdPrintLine(1, "DHT22 + HL01/MQ135");
  lcdPrintLine(2, "Cooling + Safety");
  lcdPrintLine(3, "Telegram/MySQL/TS");

  dht.begin();
  //readSafetySensors();
  connectWiFi();
  waitForStableWiFiAtStartup();
  // OTA starts later from handleOTATask() after Wi-Fi gets an IP.

  ThingSpeak.begin(thingSpeakClient);
  telegramClient.setInsecure();

  clearOldTelegramUpdatesAtBoot();
  delay(10000);
  if (WiFi.status() == WL_CONNECTED) {
    pollCoolingControl();
    reportRelayState();
  }
  
}

void loop() {
  ensureWiFiConnected();
  handleOTATask();
  checkStartupTelegram();
  

  unsigned long now = millis();

  updateManualRelayTimer();
  applyCoolingControl();
  updateBuzzerNonBlocking();

  if (now - lastSafetyReadMs >= SAFETY_INTERVAL_MS) {
    lastSafetyReadMs = now;
    readSafetySensors();
  }

  if (now - lastSensorReadMs >= SENSOR_INTERVAL_MS) {
    lastSensorReadMs = now;
    readSensor();
    handleAlarmState();
  }


  if (now - lastLcdUpdateMs >= LCD_INTERVAL_MS) {
    lastLcdUpdateMs = now;
    updateLCD();
  }

  if (now - lastThingSpeakMs >= THINGSPEAK_INTERVAL_MS) {
    lastThingSpeakMs = now;
    sendToThingSpeak();
  }

  if (now - lastMySqlPostMs >= MYSQL_INTERVAL_MS) {
    lastMySqlPostMs = now;
    sendToMySqlHttp();
  }

  if (now - lastControlPollMs >= CONTROL_POLL_INTERVAL_MS) {
    lastControlPollMs = now;
    pollCoolingControl();
  }

  if (now - lastRelayReportMs >= RELAY_REPORT_INTERVAL_MS) {
    lastRelayReportMs = now;
    reportRelayState();
  }

  clearTelegramBootUpdatesTask();

  if (now - lastTelegramPollMs >= TELEGRAM_POLL_MS) {
    lastTelegramPollMs = now;
    pollTelegram();
  }

  handlePendingResetTask();
}
