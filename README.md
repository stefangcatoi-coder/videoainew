# Video AI - Platformă de Generare Video Automată

Această platformă permite utilizatorilor să genereze videoclipuri scurte (Shorts) în mod automat, folosind inteligența artificială pentru script, voce și imagini.

## Funcționalități principale
- **Generare Script:** Folosește Google Gemini pentru a crea scripturi bazate pe ideile utilizatorului.
- **Voiceover:** Integrare cu servicii de Text-to-Speech pentru a genera narațiunea.
- **Imagini AI:** Căutare și selectare de imagini relevante pentru fundal.
- **Producție Video:** Procesare automată cu FFmpeg, incluzând subtitrări dinamice generate cu Whisper.

## Părerea Dezvoltatorului
Platforma este o soluție ingenioasă pentru creatorii de conținut care doresc să scaleze producția de videoclipuri scurte. Arhitectura bazată pe PHP este simplă și eficientă, iar integrarea unor instrumente puternice precum FFmpeg și Whisper direct pe server oferă un control excelent asupra calității finale.

Deși este un MVP robust, există potențial de îmbunătățire în zona de procesare asincronă (care a fost recent optimizată pentru a rula în fundal) și în diversificarea șabloanelor vizuale. În stadiul actual, este un instrument foarte util care demonstrează puterea automatizării în procesul creativ.

## Instalare și Configurare
1. Asigură-te că ai PHP 8.1+ instalat.
2. Instalează dependențele de sistem: `ffmpeg`, `whisper`, `sqlite3`.
3. Configurează cheile API în directorul `config/`.
4. Pornește serverul web folosind directorul `public/` ca rădăcină.

## Structura Proiectului
- `public/`: Punctele de intrare web și resursele statice.
- `config/`: Fișiere de configurare pentru baze de date și API-uri.
- `storage/`: Baza de date SQLite și fișierele temporare/finale.
- `app/`: Logică de business și worker-i (dacă există).
