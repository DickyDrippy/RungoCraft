# RungoCraft

RungoCraft is a diploma web application for an online store of construction materials.

## Main features

- product catalog with categories, filters, product cards, wishlist and comparison;
- cart and checkout;
- customer account and order history;
- admin, manager and warehouse panels;
- stock management, reservations and order processing;
- reviews, requests and support workflows;
- integration structure for Nova Poshta, Delivery Auto, payments, email and notifications.

## Stack

- PHP;
- Oracle Database / SQL;
- HTML5, CSS3, JavaScript;
- Nginx / Apache compatible hosting.

## Local configuration

Create local configuration files from examples:

```bash
cp config/database.example.php config/database.php
cp config/integrations.example.php config/integrations.php
```

Real API keys, database credentials, wallets and uploaded files must not be committed to the repository.

## Deployment

See `DEPLOY_HOSTING.md` and `SECURITY_CHECKLIST.md` if these files are present in your working package. On production keep real configuration files only on the server.
