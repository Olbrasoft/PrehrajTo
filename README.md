# PrehrajTo 🎬

Proxy pro přehrávání videí z Prehraj.to z libovolné lokace.

## Architektura

```
Uživatel (kdekoli) → Azure App Service (HTTPS) → Český PHP proxy → prehraj.to
```

Projekt řeší geo-blokaci prehraj.to pomocí tří-vrstvé architektury:

1. **Frontend (Azure App Service)** - Webová aplikace s vyhledáváním a přehrávačem
2. **PHP Proxy (Český hosting)** - Prostředník s českou IP adresou
3. **Prehraj.to** - Zdroj videí

## Komponenty

### `/azure-app/PrehrajtoProxy/`
ASP.NET Core 9.0 aplikace hostovaná na Azure App Service.

- **Frontend** (`wwwroot/index.html`) - Responzivní UI pro vyhledávání a přehrávání
- **API Endpoints**:
  - `GET /api/search?q={query}` - Vyhledávání filmů
  - `GET /api/proxy?url={prehrajto_url}` - Získání URL videa

### `/index.php`
PHP proxy běžící na českém hostingu (webzdarma.cz).

- `?action=search&q={query}` - Vyhledávání na prehraj.to
- `?url={prehrajto_url}` - Extrakce přímé URL videa

## Živá verze

- **Aplikace**: https://blaniok.azurewebsites.net
- **Zkrácená URL**: https://tinyurl.com/prehrajto

## Funkce

- 🔍 Vyhledávání filmů a seriálů
- 🎥 Přehrávání videí přímo v prohlížeči
- 📱 Responzivní design (mobil, tablet, desktop)
- 🔗 Sdílení odkazů na konkrétní videa
- ⬅️ Navigace zpět/vpřed v prohlížeči

## Technologie

- **Backend**: ASP.NET Core 9.0, PHP 8
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Hosting**: Azure App Service, Webzdarma.cz
- **Video**: HTML5 Video Player

## Lokální vývoj

```bash
# ASP.NET aplikace
cd azure-app/PrehrajtoProxy
dotnet run

# Otevři http://localhost:5000
```

## Deploy

### Azure App Service
```bash
cd azure-app/PrehrajtoProxy
dotnet publish -c Release -o ./publish
cd publish && zip -r ../deploy.zip .
# Upload deploy.zip přes Kudu API
```

### PHP Proxy
Upload `index.php` na český hosting přes FTP.

---

## Věnování

❤️

**Mamince a Leničce věnuje Jiříček a jeho virtuální asistent**

*V Olbramovicích dne 6. prosince 2025*

PS: Mám vás moc rád, i když to asi neumím dávat dobře znát.

---

## Licence

MIT
