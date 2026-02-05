# Video AI Platform

Aceasta este o platformă avansată bazată pe PHP pentru generarea de conținut video folosind Inteligența Artificială.

## Caracteristici Principale

*   **Autentificare și Management Utilizatori**: Sistem complet de login și înregistrare.
*   **Generare Video AI**: Transformă prompt-urile text în scripturi video folosind **Gemini AI**. Suportă acum atât formate scurte (Shorts), cât și videoclipuri lungi (3-5 minute).
*   **Multilingv**: Suport pentru generare de conținut în Română, Engleză, Italiană, Spaniolă, Franceză și Germană.
*   **Voiceover Profesional**: Integrare cu **Speechify** pentru a genera voci naturale din textul creat, adaptate automat limbii selectate.
*   **Management Imagini**: Căutare imagini stock și generare de imagini noi pentru slideshow-uri. Adaptare automată a orientării (Portrait pentru Shorts, Landscape pentru Long).
*   **Subtitrări Dinamice**: Generare automată de subtitrări cu evidențiere pe cuvânt și culori aleatorii pentru un aspect unic.
*   **Procesare în Fundal**: Un worker dedicat (`app/worker.php`) care gestionează producția video în mod asincron.
*   **Limitări și Planuri**: Sistem de monitorizare a utilizării resurselor pe baza planului ales.

## Tehnologii Utilizate

*   **Backend**: PHP (Custom Architecture)
*   **Bază de date**: SQLite
*   **AI (LLM)**: Google Gemini API
*   **Audio**: Speechify API
*   **UI**: Design modern, dark-themed, cu CSS personalizat.

## Structura Proiectului

*   `app/`: Conține logica de procesare în fundal (worker).
*   `config/`: Fișiere de configurare pentru baze de date și chei API.
*   `public/`: Punctul de intrare în aplicație și interfața web.
*   `storage/`: Baza de date SQLite și log-uri.
*   `views/`: Componente reutilizabile pentru interfață.

## Opinia Dezvoltatorului

Platforma este bine structurată și urmează o logică modulară clară. Separarea configurațiilor de logica de business și de interfața publică este o practică excelentă. Integrarea cu API-uri moderne de AI (Gemini, Speechify) îi oferă un potențial mare de scalare. UI-ul este curat și intuitiv, oferind o experiență de utilizare plăcută.
