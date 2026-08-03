# Data dictionary

## categories

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| id | bigint unsigned | no |  | PK |
| parent_id | bigint unsigned | yes |  | FK |

Foreign keys: parent_id -> categories.id (on delete: set null)

## posts

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| id | bigint unsigned | no |  | PK |
| user_id | bigint unsigned | no |  | FK |
| title | varchar(255) | no |  |  |
| status | enum('draft','published','archived') | no | draft |  |
| published_at | timestamp | yes |  |  |

Indexes: posts_status_index (status)

Foreign keys: user_id -> users.id (on delete: cascade)

## role_user

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| role_id | bigint unsigned | no |  | PK, FK |
| user_id | bigint unsigned | no |  | PK, FK |

Foreign keys: role_id -> roles.id (on delete: cascade); user_id -> users.id (on delete: cascade)

## roles

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| id | bigint unsigned | no |  | PK |
| name | varchar(255) | no |  |  |

## users

| Column | Type | Null | Default | Key |
| --- | --- | --- | --- | --- |
| id | bigint unsigned | no |  | PK |
| name | varchar(255) | no |  |  |
| email | varchar(255) | no |  |  |

Indexes: users_email_unique (email) UNIQUE
