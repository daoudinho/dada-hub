<?php

namespace App\Modules\Agenda\Services;

/**
 * Empreinte de la programmation d'une page : seules les lignes contenant une date ou une heure,
 * avec la ligne qui les précède (souvent le titre). Un compteur, une bannière ou un paramètre d'URL
 * qui change ne déclenchent donc pas de nouvel appel à l'IA. Repli sur toute la page si elle
 * contient trop peu de dates.
 */
class PageFingerprint
{
    private const MONTHS = 'janv?(?:ier)?|january|enero|gener|f[ée]v(?:r(?:ier)?)?|feb(?:ruary|rero|rer)?|mars|mar(?:ch|zo|ç)?|avr(?:il)?|apr(?:il)?|abril|mai|may(?:o)?|maig|juin|june?|junio|juny|juil(?:let)?|july?|julio|juliol|ao[uû]t|aug(?:ust)?|agost(?:o)?|sept?(?:embre|ember|iembre)?|setembre|oct(?:obre|ober|ubre)?|nov(?:embre|ember|iembre)?|d[ée]c(?:embre|ember)?|diciembre|desembre';

    public function of(string $markdown): string
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line) => $this->normalize($line),
            explode("\n", $markdown),
        )));

        $kept = [];
        foreach ($lines as $i => $line) {
            if ($this->hasDate($line)) {
                if ($i > 0) {
                    $kept[$i - 1] = $lines[$i - 1];
                }
                $kept[$i] = $line;
            }
        }

        return sha1(count($kept) >= 3 ? implode("\n", $kept) : implode("\n", $lines));
    }

    private function hasDate(string $line): bool
    {
        $months = self::MONTHS;

        return (bool) preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[\/.]\d{1,2}(?:[\/.]\d{2,4})?\b|\b\d{1,2}[:h]\d{2}\b/u', $line)
            || (bool) preg_match('/\b\d{1,2}(?:er|st|nd|rd|th)?\.?\s+(?:de\s+|d\')?(?:'.$months.')\b|\b(?:'.$months.')\.?\s+\d{1,2}\b/iu', $line);
    }

    /** Minuscules, sans URL ni espaces superflus. */
    private function normalize(string $line): string
    {
        $line = preg_replace('/\]\([^)]*\)/', ']', $line);
        $line = preg_replace('#https?://\S+#', '', $line);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($line)));
    }
}
