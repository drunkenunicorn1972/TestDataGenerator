# Gemini Test Data Generator

The **Gemini Test Data Generator** is a premium Shopware 6.7 plugin designed to quickly populate development and staging environments with realistic, high-quality test data. It integrates with the **Google Gemini API** for generating categories, products, and variants, and utilizes **Imagen 3** for generating product cover images.

All heavy generation tasks are processed asynchronously via the background message queue to prevent browser and request timeouts.

---

## Features

- **Dynamic Category & Product Generation**: Uses Gemini to generate realistic names, descriptions, pricing, stock, properties (e.g. Color, Size), and variant products.
- **Multilingual Support**: Automatically detects all active languages in the default Sales Channel and generates translations for all of them.
- **Generate Products for Existing Categories**: Option to generate products dynamically for child categories of a selected category, automatically falling back to generating directly under the selected category if it has no children.
- **Clean Test Environments (DEV only)**: Provides a development-only option to clear all products and property groups from the store before starting generation to keep test environments clean.
- **Translation-Only Mode**: Scans your database for missing translations on existing categories and products, translates them using Gemini, and saves them without overwriting your existing content.
- **Product Cover Images**: Optionally generates professional studio product cover images using Google Imagen 3 (with automatic pastel GD-generated images as a fallback).
- **Realistic Product Reviews**: Generates 1–10 reviews per product in matching languages, with varied star ratings (1.0 to 5.0) and randomized dates in the past (1 to 60 days) to test sorting and storefront layouts.
- **Asynchronous Execution**: Offloads heavy tasks to the Symfony Messenger queue. Users can safely close the administration page while the task executes in the background.
- **Progress Tracking**: Polls the current task status and displays visual feedback (running, completed, or failed with error logs) directly in the Admin panel.

---

## Installation

1. Copy the `TestDataGenerator` plugin folder into your Shopware directory under `custom/plugins/`.
2. Run the following commands inside the container to install, activate, and refresh:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate TestDataGenerator
   bin/console cache:clear
   ```
3. Recompile the administration panel to bundle Vue 3 assets:
   ```bash
   ./bin/build-administration.sh
   ```

---

## Configuration

Navigate to **Settings > System > Plugins > Gemini Test Data Generator** in the Shopware Admin:
- **API Key**: Enter your Google Gemini API Key.
- **LLM Model**: Select your preferred Gemini model version (e.g. `gemini-3.5-flash`, `gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-1.5-flash`, or `gemini-1.5-pro`).

---

## Usage

### Direct Access
Open the generator page from **Catalogues > Gemini Data Generator** in the main menu.

### Standard Generation
1. Configure settings such as number of categories, number of products, and whether to generate images.
2. Toggle **Add reviews for products** if you want to generate randomized reviews for each created product.
3. If in `dev` mode, toggle **Delete all products and product properties before generation** if you wish to clear existing catalog data first.
4. Click **Generate Test Data**.
5. A success toast will appear and the status card will change to **Running**.
6. Run the message queue consumer to execute the task (see below).

### Create Translations Only
1. Toggle the **Create translations only** switch.
2. This hides other parameters.
3. Click **Generate Test Data**. The queue worker will locate any existing category or product lacking translations in any of the Sales Channel's active languages, translate them, and update them.

### CLI Command
You can also trigger generation directly from the terminal inside the container:
```bash
bin/console test-data:generate
```
Options:
- `-r`, `--reviews`: Automatically generate 1–10 reviews per product with randomized timestamps in the past.

---

## Processing Background Tasks

Since tasks are dispatched to the async queue, you must run the Symfony Messenger worker to process them:
```bash
bin/console messenger:consume async -vv
```
For production or local continuous testing, you can run the worker with a limit or inside a supervisor process:
```bash
bin/console messenger:consume async --time-limit=60 --memory-limit=512M -vv
```

---

## Technical Architecture

- **Controller**: `GeneratorController.php` exposes the `/api/test-data-generator/generate` endpoint.
- **Queue Messaging**: `GenerateTestDataMessage.php` implements `AsyncMessageInterface` to serialize payloads.
- **Queue Handler**: `GenerateTestDataHandler.php` handles status updates and triggers the importer.
- **Importer Service**: `DataImporter.php` handles language/locale resolution, Gemini client prompts, tax/category mapping, repository creation, and persists products, media, and reviews (using `product_review.repository`).
- **API Client**: `GeminiClient.php` handles curl requests to Gemini JSON Schema and Imagen prediction endpoints.
