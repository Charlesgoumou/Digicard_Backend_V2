<?php

namespace App\Services;

class PdfExtractionService
{
    public static function extractTextFromBuffer($buffer)
    {
        // 1. TEST DE VIE : Si vous ne voyez pas cet écran noir au chargement,
        // c'est que Laravel ignore ce fichier (cache ou mauvais emplacement).
        // Une fois que vous avez vu "LE CODE EST ACTIF", supprimez cette ligne.
        // dd("LE CODE EST ACTIF - Si vous voyez ça, on est sur la bonne voie !");

        // 2. On crée le PDF temporaire dans le dossier Windows Temp
        $tempDir = sys_get_temp_dir();
        $tempPdf = tempnam($tempDir, 'cv_pdf_');
        // On force l'extension .pdf
        $finalPdfPath = $tempPdf . '.pdf';
        @rename($tempPdf, $finalPdfPath);

        try {
            file_put_contents($finalPdfPath, $buffer);

            // 3. Chemin EXACT de votre test manuel
            $binPath = 'C:\\poppler\\Library\\bin\\pdftotext.exe';

            // 4. Commande "Pipe" : On demande à pdftotext d'écrire sur la sortie standard (-)
            // Exactement comme votre test manuel qui a marché.
            // On ajoute -enc UTF-8 pour les accents.
            $command = sprintf(
                '"%s" -layout -enc UTF-8 "%s" -',
                $binPath,
                $finalPdfPath
            );

            // 5. Exécution directe
            // shell_exec retourne directement le texte affiché dans la console
            $text = shell_exec($command);

            // Nettoyage immédiat
            @unlink($finalPdfPath);

            // 6. Vérification
            if (is_string($text) && strlen($text) > 10) {
                return trim($text); // SUCCÈS : Retourne le texte immédiatement
            }

            // Si on est ici, c'est que ça a échoué
            return '';

        } catch (\Exception $e) {
            return '';
        }
    }
}
