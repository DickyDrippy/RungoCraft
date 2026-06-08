# RungoCraft

RungoCraft is a diploma web application for an online store of construction materials, building supplies and tools. The project demonstrates a complete e-commerce workflow: product catalog, filtering, shopping cart, checkout, customer account, order processing, warehouse stock control and delivery service integrations.

## Live Demo

Production website:

```text
https://rungocraft.xyz
```

## Main Features

### Customer Side

* product catalog with categories and subcategories;
* product cards with images, characteristics, price, stock status and reviews;
* product search and filtering by characteristics;
* wishlist and product comparison;
* shopping cart and checkout;
* customer account with profile and order history;
* product questions and product reviews;
* service review after order completion;
* calculation request form with secure file upload;
* delivery method selection;
* support chat and contact forms.

### Admin and Staff Panels

* product management;
* category and subcategory management;
* product image management;
* warehouse stock management;
* stock movements and order reservations;
* order processing;
* payment control;
* delivery shipment management;
* API logs for external integrations;
* customer requests and support tickets;
* analytics and service data tables.

### Delivery and Integrations

The project includes integration logic for:

* Nova Poshta delivery API;
* Delivery Auto API;
* delivery price calculation;
* TTN / waybill creation;
* shipment tracking;
* reCAPTCHA validation;
* email / SMS verification structure;
* payment provider configuration structure.

Real API keys and production configuration files are not stored in this repository.

## User Roles

The application supports several user roles:

* customer;
* manager;
* warehouse worker;
* administrator.

Each role has access to different parts of the system according to its responsibilities.

## Technology Stack

* PHP;
* Oracle Database;
* SQL;
* HTML5;
* CSS3;
* JavaScript;
* Nginx / Apache compatible hosting;
* external delivery and verification APIs.

## Repository Structure

```text
api/        Public API endpoints and service routes
app/        Core PHP classes and business logic
config/     Example configuration files
docs/       Project documentation
html/       Public assets
scripts/    Helper scripts for local setup
sql/        Database schema and migration files
views/      Page templates and interface views
index.php   Application entry point
router.php  Local PHP router
```

## Local Configuration

```bash
cp config/database.example.php config/database.php
cp config/integrations.example.php config/integrations.php
```

## Database

The project is designed to work with Oracle Database. Database tables and migration scripts are located in the `sql/` directory.

The database stores:

* users and roles;
* products and categories;
* product attributes and images;
* orders and order items;
* payments;
* warehouse stock;
* stock movements;
* reservations;
* delivery shipments;
* reviews;
* support requests;
* integration logs.

## Deployment

Deployment instructions are provided in:

```text
DEPLOY_HOSTING.md
```

## Security Notes

Basic security measures used in the project:

* separate example configuration files;
* restricted access to internal application directories;
* protected file download logic for calculation requests;
* reCAPTCHA validation structure;
* role-based access separation;
* API integration logs for debugging;
* server-side validation for important operations.

Before production use, API keys, database passwords and external service credentials must be generated and stored only on the server.
