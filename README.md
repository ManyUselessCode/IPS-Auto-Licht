# IPS-Auto-Licht

Symcon-Modul für Licht mit KNX und den Modi Auto, An und Aus. Eine Instanz steuert eine Lampengruppe. Vorbild ist der Home-Assistant-Blueprint „Licht | Auto“. Für Licht, das ein Taster nur umschaltet, gibt es `IPS-Licht`.

## Modi

- **Auto:** Präsenz schaltet die Lampen an, mit Helligkeitssensor nur, wenn es dunkler als die Schwelle ist. Melden alle Melder keine Präsenz mehr, gehen die Lampen aus. Ohne Melder schaltet Auto nichts.
- **An:** Lampen an, nach der Dauer zurück auf Auto. Die Dauer läuft auch über einen Symcon-Neustart weiter.
- **Aus:** Lampen aus, die Melder werden ignoriert.

## Taster

- **Einzeltaste:** Jeder Druck schaltet reihum Auto, Aus, An.
- **Wippe (Wippe Kurz, an = oben):** Im Modus Auto schaltet oben auf An und unten auf Aus. In An oder Aus schaltet jeder Druck zurück auf Auto.

## LEDs

Die LEDs zeigen den Modus: Auto Blau, An Grün, Aus Rot.

- **MDT, eine LED je Taste** (DPT 5): Blau 4, Grün 3, Rot 2.
- **MDT, zwei LEDs je Wippe:** links Blau/Grün/dunkel, rechts Blau/dunkel/Rot. Dunkel ist der Wert 0.
- **Jung** (je eine DPT-1-Instanz für Blau, Grün und Rot).

## Variablen

- **Modus:** Auto, An oder Aus. Lässt sich auch aus WebFront und App setzen, aus Skripten mit `AUTOLICHT_SetzeModus(ID, Modus, Grund)` (0 Auto, 1 An, 2 Aus).
- **Licht:** Zeigt an, ob eine Lampe der Gruppe an ist.
- **Grund:** Text zur letzten Änderung, zum Beispiel „An: Tür Wippe 2“ oder „Aus: keine Präsenz“. Zum Auswerten im Archiv aufzeichnen.

## Verhalten

- Der Zustand kommt immer aus den Status-Variablen, nie aus den Schalten-Instanzen.
- Schaltet jemand die Lampen von außen, bleibt der Modus, „Grund“ sagt „von Extern“.
- Beim Symcon-Start liest das Modul Status, Melder und Helligkeit, setzt die LEDs und prüft im Modus Auto die Präsenz.

## Voraussetzung

KNX-DPT-Instanzen: DPT 1 für Lampen, Taster, Melder und Jung-LEDs, DPT 5 für MDT-LEDs, DPT 9 für die Helligkeit. Schalten und Status sind getrennte Instanzen.
