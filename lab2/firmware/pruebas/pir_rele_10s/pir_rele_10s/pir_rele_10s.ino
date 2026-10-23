
const int PIR_PIN = 27;
const int RELAY_PIN = 26;

// Mantener la iluminacion durante 10 segundos
// desde la ultima deteccion.
const unsigned long HOLD_TIME_MS = 10000UL;

bool lightOn = false;
bool previousMotion = false;

unsigned long lastMotionTime = 0;

void setup() {
    Serial.begin(115200);

    pinMode(PIR_PIN, INPUT);

    digitalWrite(RELAY_PIN, LOW);
    pinMode(RELAY_PIN, OUTPUT);

    Serial.println("Sistema de iluminacion de pasillos");
    Serial.println("Control iniciado sin espera inicial");
}

void loop() {
    unsigned long now = millis();
    bool motion = digitalRead(PIR_PIN) == HIGH;

    if (motion) {
        // Mientras hay movimiento, renovar el tiempo.
        lastMotionTime = now;

        if (!previousMotion) {
            Serial.print("[PIR] Movimiento detectado en ");
            Serial.print(now / 1000.0);
            Serial.println(" s");
        }

        if (!lightOn) {
            lightOn = true;
            digitalWrite(RELAY_PIN, HIGH);

            Serial.println("[LUZ] ENCENDIDA");
        }
    }
    

    // Apagar solamente cuando termine el tiempo
    // y el PIR ya no detecte movimiento.
    if (lightOn &&
        !motion &&
        (now - lastMotionTime >= HOLD_TIME_MS)) {

        lightOn = false;
        digitalWrite(RELAY_PIN, LOW);

        Serial.println("[LUZ] APAGADA por inactividad");
    }

    previousMotion = motion;
}
