# Diagrama Entidad-Relacion

```mermaid
erDiagram
    USERS {
        int id PK
        string name
        string email UK
        datetime email_verified_at
        string password
        datetime created_at
        datetime updated_at
    }

    REGIONES {
        int id PK
        string nombre UK
        datetime created_at
        datetime updated_at
    }

    COMUNAS {
        int id PK
        int region_id FK
        string nombre
        datetime created_at
        datetime updated_at
    }

    PROPIETARIOS {
        int id PK
        int user_id FK_UK
        string nombre
        string apellido
        string rut UK
        string email UK
        string telefono
        string direccion
        int region_id FK_NULL
        int comuna_id FK_NULL
        string nacionalidad
        string profesion
        string estado_civil
        datetime created_at
        datetime updated_at
    }

    ETAPAS {
        int id PK
        int numero
        string nombre
        datetime created_at
        datetime updated_at
    }

    LOTES {
        int id PK
        string codigo UK
        decimal hectareas
        int metros_cuadrados
        int valor_lote
        string estado
        int etapa_id FK_NULL
        text notas
        datetime created_at
        datetime updated_at
    }

    LOTE_PROPIETARIO {
        int id PK
        int lote_id FK
        int propietario_id FK
        datetime assigned_at
        datetime unassigned_at NULL
        string status
        datetime created_at
        datetime updated_at
    }

    EXPENSE_CATEGORIES {
        int id PK
        string name UK
        text description
        bool is_active
        datetime created_at
        datetime updated_at
    }

    EXPENSES {
        int id PK
        int expense_category_id FK
        string title
        text description
        decimal amount
        date expense_date
        date due_date NULL
        string distribution_type
        string status
        int created_by FK_NULL
        decimal funded_amount
        datetime distributed_at NULL
        datetime paid_at NULL
        text notes
        datetime created_at
        datetime updated_at
    }

    EXPENSE_FUNDING_PAYMENTS {
        int id PK
        int expense_id FK
        decimal amount
        date payment_date
        bool is_void
        datetime voided_at NULL
        int voided_by FK_NULL
        int created_by FK_NULL
        int updated_by FK_NULL
        text void_reason
        text notes
        datetime created_at
        datetime updated_at
    }

    PARTNER_CHARGES {
        int id PK
        int propietario_id FK
        int expense_id FK
        decimal amount
        decimal paid_amount
        decimal remaining_amount
        string status
        date due_date NULL
        string calculation_type
        decimal partner_hectares_at_moment NULL
        decimal total_hectares_at_moment NULL
        decimal percentage_applied NULL
        text description
        text calculation_notes
        datetime created_at
        datetime updated_at
    }

    COLLECTIONS {
        int id PK
        int propietario_id FK
        decimal amount
        decimal applied_amount
        decimal unapplied_amount
        date collection_date
        string collection_method
        string status
        string reference NULL
        text notes NULL
        int created_by FK_NULL
        datetime created_at
        datetime updated_at
    }

    COLLECTION_ALLOCATIONS {
        int id PK
        int collection_id FK
        int partner_charge_id FK
        decimal amount
        datetime allocated_at
        int created_by FK_NULL
        datetime created_at
        datetime updated_at
    }

    ACCOUNTING_PERIODS {
        int id PK
        int year
        int month
        date period_start
        date period_end
        string status
        string close_folio UK_NULL
        datetime closed_at NULL
        int closed_by FK_NULL
        datetime reopened_at NULL
        int reopened_by FK_NULL
        text reopen_reason NULL
        datetime created_at
        datetime updated_at
    }

    ACCOUNTING_PERIOD_SNAPSHOTS {
        int id PK
        int accounting_period_id FK_UK
        decimal total_charges
        decimal total_collections
        decimal total_allocations
        decimal pending_balance
        decimal credit_balance
        decimal overdue_1_30
        decimal overdue_31_60
        decimal overdue_61_90
        decimal overdue_90_plus
        text metadata NULL
        datetime created_at
        datetime updated_at
    }

    %% Relaciones maestras
    USERS ||--o| PROPIETARIOS : "perfil propietario"
    REGIONES ||--o{ COMUNAS : "tiene"
    REGIONES ||--o{ PROPIETARIOS : "ubica"
    COMUNAS ||--o{ PROPIETARIOS : "ubica"

    ETAPAS ||--o{ LOTES : "agrupa"
    LOTES ||--o{ LOTE_PROPIETARIO : "asignaciones"
    PROPIETARIOS ||--o{ LOTE_PROPIETARIO : "asignaciones"

    EXPENSE_CATEGORIES ||--o{ EXPENSES : "clasifica"
    USERS ||--o{ EXPENSES : "crea"

    EXPENSES ||--o{ EXPENSE_FUNDING_PAYMENTS : "financiamiento"
    USERS ||--o{ EXPENSE_FUNDING_PAYMENTS : "auditoria"

    EXPENSES ||--o{ PARTNER_CHARGES : "distribuye en cargos"
    PROPIETARIOS ||--o{ PARTNER_CHARGES : "recibe cargos"

    PROPIETARIOS ||--o{ COLLECTIONS : "realiza pagos"
    USERS ||--o{ COLLECTIONS : "registra"

    COLLECTIONS ||--o{ COLLECTION_ALLOCATIONS : "se aplica en"
    PARTNER_CHARGES ||--o{ COLLECTION_ALLOCATIONS : "recibe aplicacion"
    USERS ||--o{ COLLECTION_ALLOCATIONS : "auditoria"

    ACCOUNTING_PERIODS ||--|| ACCOUNTING_PERIOD_SNAPSHOTS : "snapshot unico"
    USERS ||--o{ ACCOUNTING_PERIODS : "cierre/reapertura"
```
