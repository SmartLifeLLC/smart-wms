# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Smart WMS (Warehouse Management System) - A Laravel 12 + Filament 4 admin panel application for warehouse management.

This WMS system integrates with a core business system (基幹システム) by:
- Referencing shared database tables for master data
- Managing WMS-specific operations in dedicated `wms_` tables
- Tracking stock reservations and allocations via columns added to the core `real_stocks` table
- **No foreign keys** - data integrity is maintained at the application/job level

**Tech Stack:**
- **Laravel 12** (PHP 8.2+)
- **Filament 4** (Admin Panel Framework)
  - Uses `Filament\Schemas\Schema` instead of `Filament\Forms\Form`
  - Uses `Filament\Schemas\Components\Section` for form sections
  - Actions: Import from `Filament\Actions\*` (not `Filament\Tables\Actions\*`)
  - Table methods: `->recordActions()` and `->toolbarActions()` (not `->actions()` and `->bulkActions()`)
- **Livewire 3** (For reactive components)
- **Tailwind CSS 4** (Styling)
- **Vite** (Asset bundling)
- **MySQL** (Production database via `sakemaru` connection)
- **SQLite** (Default database, configurable)

**Full Specification:** See `storage/specifications/2025-10-13-wms-specification.md` for complete system requirements.

## Development Commands

### Initial Setup
```bash
composer setup  # Installs dependencies, generates key, runs migrations, builds assets
```

### Development
```bash
composer dev    # Runs server, queue, logs, and vite concurrently
# - Laravel server: http://localhost:8000
# - Queue worker with max 1 try
# - Real-time logs via Pail
# - Vite dev server for hot reload

# Individual commands:
php artisan serve              # Start development server
php artisan queue:listen       # Run queue worker
php artisan pail              # View real-time logs
npm run dev                   # Start Vite dev server
```

### Testing
```bash
composer test          # Clear config cache and run PHPUnit tests
php artisan test       # Run all tests
php artisan test --filter=TestName  # Run specific test
```

### Building for Production
```bash
npm run build         # Build production assets
```

### Database
```bash
php artisan migrate              # Run migrations
php artisan migrate:fresh        # Drop all tables and re-run migrations
php artisan migrate:fresh --seed # Fresh migration with seeding
php artisan db:seed             # Run database seeders
```

### Code Quality
```bash
./vendor/bin/pint     # Laravel Pint (code formatter)
```

### Filament Commands
```bash
php artisan filament:user                    # Create a Filament admin user
php artisan make:filament-resource ModelName # Create a Filament resource
php artisan make:filament-page PageName      # Create a Filament page
php artisan make:filament-widget WidgetName  # Create a Filament widget
```

## Architecture

### Directory Structure

- `app/` - Application code
  - `Http/Controllers/` - HTTP controllers
  - `Models/` - Eloquent models
  - `Providers/` - Service providers
    - `Filament/` - Filament panel providers (AdminPanelProvider.php)
- `database/`
  - `migrations/` - Database migrations
  - `factories/` - Model factories for testing
  - `seeders/` - Database seeders
- `resources/`
  - `views/` - Blade templates
  - `css/` - CSS files
  - `js/` - JavaScript files
- `routes/` - Route definitions
  - `web.php` - Web routes
  - `console.php` - Console commands
- `tests/` - PHPUnit tests
  - `Feature/` - Feature tests
  - `Unit/` - Unit tests
- `config/` - Configuration files
- `public/` - Public assets (entry point)
- `storage/` - Application storage (logs, cache, uploads)

### Filament Admin Panel

The admin panel is accessible at `/admin` and configured in `app/Providers/Filament/AdminPanelProvider.php`.

**Key Concepts:**
- **Resources**: CRUD interfaces for Eloquent models (create with `make:filament-resource`)
- **Pages**: Custom admin pages (create with `make:filament-page`)
- **Widgets**: Dashboard widgets and stats (create with `make:filament-widget`)
- **Actions**: Buttons and modals for user interactions
- **Forms**: Form builder with validation
- **Tables**: Advanced data tables with filters, sorting, bulk actions

### Database

- Uses SQLite by default (`database/database.sqlite`)
- Configured via `.env` file
- Eloquent ORM for database interactions
- Migrations stored in `database/migrations/`

### Frontend

- Tailwind CSS 4 for styling
- Vite for asset bundling
- Filament uses Livewire 3 for reactive components
- No separate frontend framework needed for admin panel

## Common Development Patterns

### Creating a New Resource

For a warehouse management system, you'll commonly create resources for entities like Products, Warehouses, Inventory, etc.

```bash
php artisan make:model Product -mfs  # Model with migration, factory, seeder
php artisan make:filament-resource Product --generate
```

The `--generate` flag will scaffold forms and tables based on the model's database columns.

### Filament Resource Structure

Resources are located in `app/Filament/Resources/` and consist of:
- `{Model}Resource.php` - Main resource class with table and form definitions
- `{Model}Resource/Pages/` - CRUD pages (Create, Edit, List)

### Environment Configuration

Key `.env` variables:
- `APP_ENV` - Environment (local, production)
- `APP_DEBUG` - Debug mode
- `APP_URL` - Application URL
- `DB_CONNECTION` - Database driver (sqlite, mysql, pgsql)
- `QUEUE_CONNECTION` - Queue driver
- `MAIL_MAILER` - Mail driver

## Dependencies Management

```bash
composer install           # Install PHP dependencies
composer update           # Update PHP dependencies
npm install              # Install Node.js dependencies
npm update              # Update Node.js dependencies
```

## Key Laravel 12 Features

- Improved performance and developer experience
- Enhanced Eloquent ORM
- Better queue management
- Advanced validation rules
- Modern PHP 8.2+ features support

---

## WMS-Specific Architecture

### Core System Integration

**Shared Database Tables (Read from Core System):**
- Master data tables (clients, warehouses, items, locations, etc.)
- `real_stocks` - Core inventory table with WMS tracking columns:
  - `wms_reserved_qty` - Stock reserved for picking (INT, default 0)
  - `wms_picking_qty` - Stock currently being picked (INT, default 0)
  - `wms_lock_version` - Optimistic locking version (INT, default 0)

**WMS-Managed Tables (Write to WMS Database):**
- `wms_reservations` - Stock allocation records
- `wms_idempotency_keys` - Idempotency tracking for API calls
- `wms_waves` - Wave/batch picking operations (future STEP 2)
- `wms_picking_tasks` - Picking task management (future STEP 3)
- Additional tables for receipts, moves, counts, etc. (future steps)

### Stock Allocation Strategy

**FEFO → FIFO Priority:**
1. **FEFO (First Expiry, First Out)**: Prioritize by `expiration_date` ASC (NULL values last)
2. **FIFO (First In, First Out)**: Within same expiry date, sort by `received_at` ASC
3. **Tie-breaker**: Sort by `real_stock_id` ASC

**Allocation Process:**
1. Query `wms_v_stock_available` view for candidate stock
2. Filter by `warehouse_id`, `item_id`, and `available_for_wms > 0`
3. Sort according to FEFO→FIFO rules
4. Create `wms_reservations` records within transaction
5. Simultaneously increment `real_stocks.wms_reserved_qty`

### Database View

**`wms_v_stock_available`** - Real-time available stock calculation:
```sql
SELECT
  rs.id AS real_stock_id,
  rs.client_id,
  rs.warehouse_id,
  rs.item_id,
  rs.expiration_date,
  rs.received_at,
  rs.purchase_id,
  rs.price AS unit_cost,
  rs.current_quantity,
  -- Calculate available quantity for WMS after reservations
  GREATEST(rs.available_quantity - (rs.wms_reserved_qty + rs.wms_picking_qty), 0) AS available_for_wms,
  rs.wms_reserved_qty,
  rs.wms_picking_qty
FROM real_stocks rs
```

### Implementation Phases (7 Steps)

**STEP 1** (Current): Reservation Foundation
- ✅ `wms_reservations` table
- ✅ `wms_idempotency_keys` table
- ✅ WMS columns added to `real_stocks`
- ✅ `wms_v_stock_available` view
- 🔄 Allocation API with optimistic locking

**STEP 2**: Wave Generation & Shipping Dashboard
**STEP 3**: Picking Tasks (scanning, discrepancies)
**STEP 4**: Shipping Confirmation & COGS
**STEP 5**: Receipt & Putaway
**STEP 6**: Inventory Counts & Adjustments
**STEP 7**: Container History & KPIs

### Key Design Principles

1. **No Foreign Keys**: All relationships managed at application level for flexibility
2. **Optimistic Locking**: Use `wms_lock_version` to detect concurrent stock updates
3. **Idempotency**: All allocation operations must be idempotent via `wms_idempotency_keys`
4. **Audit Trail**: All operations logged to `wms_audit_logs` (future step)
5. **Transaction Safety**: Stock reservations must be atomic (reservation + real_stocks update)

### Testing Focus Areas

- Stock availability calculation after reservations
- FEFO→FIFO sorting correctness
- Reservation and `real_stocks.wms_reserved_qty` consistency
- Optimistic lock conflict detection via `wms_lock_version`
- Idempotency of allocation operations
- Exception handling: shortages, discrepancies, concurrent updates