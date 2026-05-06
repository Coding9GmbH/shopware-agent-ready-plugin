# Installation

## Via Plugin Manager

1. Upload the plugin zip in *Extensions → My extensions → Upload extension*.
2. Click *Install* and then *Activate*.
3. Open *Extensions → My extensions → Agent Ready → Configure* and review the
   defaults. Sensible defaults are pre-set; nothing more is required.

## Via Composer

```bash
composer require coding9/shopware-agent-ready
bin/console plugin:refresh
bin/console plugin:install --activate Coding9AgentReady
bin/console cache:clear
```

## After installation

Verify with:

```bash
curl -sI https://your-shop.example/ | grep -i '^link:'
curl -s https://your-shop.example/.well-known/api-catalog
curl -s -H 'Accept: text/markdown' https://your-shop.example/ | head
curl -s https://your-shop.example/robots.txt
```

## Known caveat — robots.txt

If your hosting setup ships a static `public/robots.txt`, it will shadow the
plugin's dynamic `robots.txt`. Either delete the static file or copy the
plugin output into it.
