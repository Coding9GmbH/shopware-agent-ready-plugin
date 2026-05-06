# Installation

## Über den Plugin Manager

1. Plugin-Zip hochladen unter *Erweiterungen → Meine Erweiterungen →
   Erweiterung hochladen*.
2. *Installieren* klicken, anschließend *Aktivieren*.
3. *Erweiterungen → Meine Erweiterungen → Agent Ready → Konfigurieren*
   öffnen. Die Defaults sind sinnvoll vorbelegt — mehr ist nicht nötig.

## Über Composer

```bash
composer require coding9/shopware-agent-ready
bin/console plugin:refresh
bin/console plugin:install --activate Coding9AgentReady
bin/console cache:clear
```

## Nach der Installation

Verifizieren:

```bash
curl -sI https://ihr-shop.example/ | grep -i '^link:'
curl -s https://ihr-shop.example/.well-known/api-catalog
curl -s -H 'Accept: text/markdown' https://ihr-shop.example/ | head
curl -s https://ihr-shop.example/robots.txt
```

## Bekannter Stolperstein — robots.txt

Falls Ihre Hosting-Konfiguration eine statische `public/robots.txt` ausliefert,
überschattet diese die dynamische Plugin-Variante. Entweder die statische Datei
löschen oder den Plugin-Output dort einkopieren.
