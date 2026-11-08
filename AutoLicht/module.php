<?php

declare(strict_types=1);

/**
 * Eine Instanz steuert eine Lampengruppe mit den Modi Auto, An und Aus, nach
 * dem Home-Assistant-Blueprint "Licht | Auto".
 * Auto: Präsenz schaltet an (mit Helligkeit nur, wenn dunkel), keine Präsenz an
 * allen Meldern schaltet aus. An: alle Lampen an, nach der Dauer zurück auf Auto.
 * Aus: alle Lampen aus, Melder werden ignoriert. LEDs zeigen den Modus.
 * Der Zustand kommt immer aus den Status-Variablen, nie aus den Befehlsinstanzen.
 */
class AutoLicht extends IPSModule
{
    private const MODUS_AUTO = 0;
    private const MODUS_AN = 1;
    private const MODUS_AUS = 2;
    private const MODUS_NAME = [self::MODUS_AUTO => 'Auto', self::MODUS_AN => 'An', self::MODUS_AUS => 'Aus'];

    private const LED_AUS = 0;
    private const LED_ROT = 2;
    private const LED_GRUEN = 3;
    private const LED_BLAU = 4;

    private const STATUS_FEHLER = 201;
    private const BEFEHL_SPERRE_S = 5;      // so lange zählt eine Statusänderung als eigene
    private const START_WARTEN_MS = 30000;  // Gateway und Bus nach dem Start abwarten
    private const ANTWORT_WARTEN_MS = 5000; // Zeit für die Antworten auf die Leseanfragen

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Lampen', '[]');
        $this->RegisterPropertyInteger('DauerAn', 60);
        $this->RegisterPropertyString('Melder', '[]');
        $this->RegisterPropertyInteger('HelligkeitID', 0);
        $this->RegisterPropertyFloat('Schwelle', 250);
        $this->RegisterPropertyString('Tasten', '[]');
        $this->RegisterPropertyString('Wippen', '[]');
        $this->RegisterPropertyString('LedsMdt', '[]');
        $this->RegisterPropertyString('LedsMdtWippe', '[]');
        $this->RegisterPropertyString('LedsJung', '[]');

        $this->RegisterTimer('DauerAn', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'DauerAbgelaufen\', 0);');
        $this->RegisterTimer('Start', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'StartSchritt\', 0);');

        $this->RegisterVariableInteger('Modus', 'Modus', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'LAYOUT'       => 1, // Reihe
            'OPTIONS'      => json_encode([
                ['Value' => self::MODUS_AUTO, 'Caption' => 'Auto', 'IconActive' => true, 'IconValue' => 'circle-a'],
                ['Value' => self::MODUS_AN, 'Caption' => 'An', 'IconActive' => true, 'IconValue' => 'lightbulb-on'],
                ['Value' => self::MODUS_AUS, 'Caption' => 'Aus', 'IconActive' => true, 'IconValue' => 'lightbulb']
            ])
        ], 1);
        $this->RegisterVariableBoolean('Licht', 'Licht', [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'USE_ICON_FALSE' => true,
            'ICON_TRUE'      => 'lightbulb-on',
            'ICON_FALSE'     => 'lightbulb',
            'GLOW_COLOR'     => 0xFFC766,
            'GLOW_INTENSITY' => 50,
            'USAGE_TYPE'     => 0
        ], 2);
        $this->RegisterVariableString('Grund', 'Grund', '', 3);
        $this->EnableAction('Modus');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $sender => $nachrichten) {
            foreach ($nachrichten as $nachricht) {
                $this->UnregisterMessage($sender, $nachricht);
            }
        }
        foreach ($this->GetReferenceList() as $referenz) {
            $this->UnregisterReference($referenz);
        }
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Einrichten();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELMESSAGE) {
            if ($Data[0] == KR_READY) {
                $this->Einrichten(true);
                $this->SetBuffer('StartSchritt', 'Lesen');
                $this->SetTimerInterval('Start', self::START_WARTEN_MS);
            }
            return;
        }
        if ($Message != VM_UPDATE || $this->GetStatus() != IS_ACTIVE) {
            return;
        }

        foreach ($this->Liste('Tasten') as $taste) {
            if ($taste['VariableID'] == $SenderID) {
                $this->TasteGedrueckt($taste['Name']);
                return;
            }
        }
        foreach ($this->Liste('Wippen') as $wippe) {
            if ($wippe['VariableID'] == $SenderID) {
                $this->WippeGedrueckt($wippe['Name'], (bool) $Data[0]);
                return;
            }
        }
        if (!$Data[1]) {
            return; // Status, Melder und Helligkeit zählen nur, wenn sich der Wert geändert hat
        }
        foreach ($this->Liste('Lampen') as $lampe) {
            if ($lampe['StatusID'] == $SenderID) {
                $this->StatusGeaendert();
                return;
            }
        }
        foreach ($this->Liste('Melder') as $melder) {
            if ($melder['VariableID'] == $SenderID) {
                $this->AutoPruefen();
                return;
            }
        }
        if ($SenderID == $this->ReadPropertyInteger('HelligkeitID')) {
            $this->HelligkeitGeaendert((float) $Data[0]);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Modus':
                $modus = (int) $Value;
                if (!isset(self::MODUS_NAME[$modus])) {
                    throw new Exception('Unbekannter Modus: ' . $Value);
                }
                $this->ModusSetzen($modus, 'App');
                break;
            case 'DauerAbgelaufen':
                $this->SetTimerInterval('DauerAn', 0);
                if ($this->Modus() == self::MODUS_AN) {
                    $this->ModusSetzen(self::MODUS_AUTO, 'Dauer ' . $this->ReadPropertyInteger('DauerAn') . ' min abgelaufen');
                }
                break;
            case 'StartSchritt':
                $this->StartSchritt();
                break;
            default:
                throw new Exception('Unbekannter Ident: ' . $Ident);
        }
    }

    /**
     * Setzt den Modus aus einem Skript: 0 Auto, 1 An, 2 Aus.
     */
    public function SetzeModus(int $Modus, string $Grund): void
    {
        if (!isset(self::MODUS_NAME[$Modus])) {
            throw new Exception('Unbekannter Modus: ' . $Modus);
        }
        $this->ModusSetzen($Modus, $Grund);
    }

    /**
     * Beim Start ($start) wartet das Modul mit LEDs, Dauer und Präsenz auf die
     * gelesenen Werte in StartSchritt, statt mit dem alten Stand zu schalten.
     */
    private function Einrichten(bool $start = false): void
    {
        $lampen = $this->Liste('Lampen');
        if (count($lampen) == 0) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $fehler = $this->Pruefen();
        if ($fehler != '') {
            $this->LogMessage($fehler, KL_ERROR);
            $this->SetStatus(self::STATUS_FEHLER);
            return;
        }

        foreach ($lampen as $lampe) {
            $this->RegisterMessage($lampe['StatusID'], VM_UPDATE);
            $this->RegisterReference($lampe['StatusID']);
            $this->RegisterReference($lampe['SchaltenID']);
        }
        foreach (['Tasten', 'Wippen', 'Melder'] as $liste) {
            foreach ($this->Liste($liste) as $eintrag) {
                $this->RegisterMessage($eintrag['VariableID'], VM_UPDATE);
                $this->RegisterReference($eintrag['VariableID']);
            }
        }
        $helligkeit = $this->ReadPropertyInteger('HelligkeitID');
        if ($helligkeit > 0) {
            $this->RegisterMessage($helligkeit, VM_UPDATE);
            $this->RegisterReference($helligkeit);
            $this->SetBuffer('Lux', (string) GetValue($helligkeit));
        }
        foreach ($this->Liste('LedsMdt') as $led) {
            $this->RegisterReference($led['InstanceID']);
        }
        foreach ($this->Liste('LedsMdtWippe') as $led) {
            $this->RegisterReference($led['LinksID']);
            $this->RegisterReference($led['RechtsID']);
        }
        foreach ($this->Liste('LedsJung') as $led) {
            $this->RegisterReference($led['BlauID']);
            $this->RegisterReference($led['GruenID']);
            $this->RegisterReference($led['RotID']);
        }

        $this->SetStatus(IS_ACTIVE);
        if (!$start) {
            $this->ZustandZeigen();
            $this->DauerStarten();
        }
    }

    private function Pruefen(): string
    {
        $falsch = [];
        foreach ($this->Liste('Lampen') as $lampe) {
            if (!IPS_InstanceExists($lampe['SchaltenID'])) {
                $falsch[] = 'Lampe Schalten ' . $lampe['SchaltenID'];
            }
            if (!IPS_VariableExists($lampe['StatusID'])) {
                $falsch[] = 'Lampe Status ' . $lampe['StatusID'];
            }
        }
        foreach (['Tasten' => 'Taste', 'Wippen' => 'Wippe', 'Melder' => 'Melder'] as $liste => $name) {
            foreach ($this->Liste($liste) as $eintrag) {
                if (!IPS_VariableExists($eintrag['VariableID'])) {
                    $falsch[] = $name . ' ' . $eintrag['VariableID'];
                }
            }
        }
        $helligkeit = $this->ReadPropertyInteger('HelligkeitID');
        if ($helligkeit > 0 && !IPS_VariableExists($helligkeit)) {
            $falsch[] = 'Helligkeit ' . $helligkeit;
        }
        foreach ($this->Liste('LedsMdt') as $led) {
            if (!IPS_InstanceExists($led['InstanceID'])) {
                $falsch[] = 'MDT-LED ' . $led['InstanceID'];
            }
        }
        foreach ($this->Liste('LedsMdtWippe') as $led) {
            if (!IPS_InstanceExists($led['LinksID']) || !IPS_InstanceExists($led['RechtsID'])) {
                $falsch[] = 'MDT-Wippen-LED ' . $led['LinksID'] . '/' . $led['RechtsID'];
            }
        }
        foreach ($this->Liste('LedsJung') as $led) {
            if (!IPS_InstanceExists($led['BlauID']) || !IPS_InstanceExists($led['GruenID']) || !IPS_InstanceExists($led['RotID'])) {
                $falsch[] = 'Jung-LED ' . $led['BlauID'] . '/' . $led['GruenID'] . '/' . $led['RotID'];
            }
        }
        return count($falsch) ? 'Ungültige IDs: ' . implode(', ', $falsch) : '';
    }

    private function TasteGedrueckt(string $name): void
    {
        // reihum Auto, Aus, An
        $naechster = [self::MODUS_AUTO => self::MODUS_AUS, self::MODUS_AUS => self::MODUS_AN, self::MODUS_AN => self::MODUS_AUTO];
        $this->ModusSetzen($naechster[$this->Modus()], $name);
    }

    private function WippeGedrueckt(string $name, bool $oben): void
    {
        if ($this->Modus() == self::MODUS_AUTO) {
            $this->ModusSetzen($oben ? self::MODUS_AN : self::MODUS_AUS, $name . ($oben ? ' oben' : ' unten'));
        } else {
            $this->ModusSetzen(self::MODUS_AUTO, $name);
        }
    }

    private function ModusSetzen(int $modus, string $ausloeser): void
    {
        $this->SetValue('Modus', $modus);
        $this->SetValue('Grund', self::MODUS_NAME[$modus] . ': ' . $ausloeser);
        $this->ZustandZeigen();

        switch ($modus) {
            case self::MODUS_AN:
                $this->Schalten(true);
                $this->SetTimerInterval('DauerAn', $this->ReadPropertyInteger('DauerAn') * 60000);
                break;
            case self::MODUS_AUS:
                $this->Schalten(false);
                $this->SetTimerInterval('DauerAn', 0);
                break;
            default:
                $this->SetTimerInterval('DauerAn', 0);
                $this->AutoPruefen();
        }
    }

    private function AutoPruefen(): void
    {
        $melder = $this->Liste('Melder');
        if ($this->Modus() != self::MODUS_AUTO || count($melder) == 0) {
            return;
        }
        $praesenz = false;
        foreach ($melder as $eintrag) {
            if (GetValueBoolean($eintrag['VariableID'])) {
                $praesenz = true;
            }
        }
        if ($praesenz) {
            if ($this->Dunkel() && !$this->IstAn()) {
                $this->Schalten(true, 'Auto an: Präsenz');
            }
        } elseif ($this->IstAn()) {
            $this->Schalten(false, 'Auto aus: keine Präsenz');
        }
    }

    private function HelligkeitGeaendert(float $lux): void
    {
        $vorher = $this->GetBuffer('Lux');
        $this->SetBuffer('Lux', (string) $lux);
        $schwelle = $this->ReadPropertyFloat('Schwelle');
        // nur beim Unterschreiten der Schwelle, nicht bei jedem Wert
        if ($lux < $schwelle && ($vorher === '' || (float) $vorher >= $schwelle)) {
            $this->AutoPruefen();
        }
    }

    private function Dunkel(): bool
    {
        $helligkeit = $this->ReadPropertyInteger('HelligkeitID');
        if ($helligkeit == 0) {
            return true;
        }
        return (float) GetValue($helligkeit) < $this->ReadPropertyFloat('Schwelle');
    }

    private function Schalten(bool $wert, string $grund = ''): void
    {
        $this->SetBuffer('Befehl', (string) time());
        if ($grund != '') {
            $this->SetValue('Grund', $grund);
        }
        foreach ($this->Liste('Lampen') as $lampe) {
            if (!@KNX_WriteDPT1($lampe['SchaltenID'], $wert)) {
                $this->LogMessage('Schalten fehlgeschlagen: ' . $lampe['SchaltenID'], KL_ERROR);
            }
        }
    }

    private function StatusGeaendert(): void
    {
        $war = $this->GetValue('Licht');
        $an = $this->IstAn();
        if ($an != $war) {
            $this->SetValue('Licht', $an);
            if (time() - (int) $this->GetBuffer('Befehl') > self::BEFEHL_SPERRE_S) {
                $this->SetValue('Grund', ($an ? 'An' : 'Aus') . ': von Extern');
            }
        }
    }

    private function StartSchritt(): void
    {
        if ($this->GetBuffer('StartSchritt') == 'Lesen') {
            $this->SetBuffer('StartSchritt', 'Pruefen');
            $this->SetTimerInterval('Start', self::ANTWORT_WARTEN_MS);
            foreach ($this->Liste('Lampen') as $lampe) {
                $this->StatusAnfragen($lampe['StatusID']);
            }
            foreach ($this->Liste('Melder') as $melder) {
                $this->StatusAnfragen($melder['VariableID']);
            }
            if ($this->ReadPropertyInteger('HelligkeitID') > 0) {
                $this->StatusAnfragen($this->ReadPropertyInteger('HelligkeitID'));
            }
            return;
        }

        $this->SetTimerInterval('Start', 0);
        if ($this->GetStatus() != IS_ACTIVE) {
            return;
        }
        if ($this->ReadPropertyInteger('HelligkeitID') > 0) {
            $this->SetBuffer('Lux', (string) GetValue($this->ReadPropertyInteger('HelligkeitID')));
        }
        $this->ZustandZeigen();
        $this->DauerStarten();
        $this->AutoPruefen();
    }

    private function StatusAnfragen(int $variable): void
    {
        $instanz = IPS_GetParent($variable);
        if (IPS_InstanceExists($instanz) && function_exists('KNX_RequestStatus')) {
            @KNX_RequestStatus($instanz);
            IPS_Sleep(200);
        }
    }

    /**
     * Startet im Modus An die Restdauer, gerechnet ab der letzten Änderung des Modus.
     * So läuft die Dauer über einen Neustart oder ein Übernehmen der Einstellungen weiter.
     */
    private function DauerStarten(): void
    {
        if ($this->Modus() != self::MODUS_AN) {
            $this->SetTimerInterval('DauerAn', 0);
            return;
        }
        $seit = IPS_GetVariable($this->GetIDForIdent('Modus'))['VariableChanged'];
        $rest = $this->ReadPropertyInteger('DauerAn') * 60 - (time() - $seit);
        if ($rest <= 0) {
            $this->ModusSetzen(self::MODUS_AUTO, 'Dauer ' . $this->ReadPropertyInteger('DauerAn') . ' min abgelaufen');
        } else {
            $this->SetTimerInterval('DauerAn', $rest * 1000);
        }
    }

    private function ZustandZeigen(): void
    {
        $this->SetValue('Licht', $this->IstAn());
        $modus = $this->Modus();

        $farbe = [self::MODUS_AUTO => self::LED_BLAU, self::MODUS_AN => self::LED_GRUEN, self::MODUS_AUS => self::LED_ROT][$modus];
        $links = [self::MODUS_AUTO => self::LED_BLAU, self::MODUS_AN => self::LED_GRUEN, self::MODUS_AUS => self::LED_AUS][$modus];
        $rechts = [self::MODUS_AUTO => self::LED_BLAU, self::MODUS_AN => self::LED_AUS, self::MODUS_AUS => self::LED_ROT][$modus];

        foreach ($this->Liste('LedsMdt') as $led) {
            @KNX_WriteDPT5($led['InstanceID'], $farbe);
        }
        foreach ($this->Liste('LedsMdtWippe') as $led) {
            @KNX_WriteDPT5($led['LinksID'], $links);
            @KNX_WriteDPT5($led['RechtsID'], $rechts);
        }
        foreach ($this->Liste('LedsJung') as $led) {
            @KNX_WriteDPT1($led['BlauID'], $modus == self::MODUS_AUTO);
            @KNX_WriteDPT1($led['GruenID'], $modus == self::MODUS_AN);
            @KNX_WriteDPT1($led['RotID'], $modus == self::MODUS_AUS);
        }
    }

    private function Modus(): int
    {
        $modus = $this->GetValue('Modus');
        return isset(self::MODUS_NAME[$modus]) ? $modus : self::MODUS_AUTO;
    }

    private function IstAn(): bool
    {
        foreach ($this->Liste('Lampen') as $lampe) {
            if (IPS_VariableExists($lampe['StatusID']) && GetValueBoolean($lampe['StatusID'])) {
                return true;
            }
        }
        return false;
    }

    private function Liste(string $name): array
    {
        return json_decode($this->ReadPropertyString($name), true) ?: [];
    }
}
