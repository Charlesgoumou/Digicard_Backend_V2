<?php

return [
    // La clé doit être 'bin_path' pour correspondre à votre Service
    'bin_path' => env('POPPLER_BIN_PATH',
        (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            // ATTENTION AUX DOUBLES SLASH (\\) OBLIGATOIRES SUR WINDOWS
            ? 'C:\\poppler\\Library\\bin\\pdftotext.exe'
            : '/usr/bin/pdftotext'
    ),
];
