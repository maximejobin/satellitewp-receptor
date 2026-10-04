# Accès MySQL à la base « Zone Client » (CRM)

Manager lit la base `clients` et n'écrit que dans quelques tables. On crée
l'utilisateur `umanager` dans RunCloud, puis on remplace ses droits hérités par
des droits table par table.

**Restreindre l'hôte.** Remplacer `<IP_MANAGER>` par l'adresse IP publique du
serveur Manager. N'utiliser `'%'` (n'importe quelle adresse) qu'en dernier
recours, et seulement derrière un pare-feu qui limite le port 3306.

```sql
-- 1. Retirer les droits globaux hérités
REVOKE ALL PRIVILEGES ON `clients`.* FROM 'umanager'@'<IP_MANAGER>';

-- 2. Lecture seule
GRANT SELECT ON `clients`.swp_clients              TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_licenses             TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_maintenance_plans    TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_products             TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_subscriptions        TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_vulnerabilities      TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_website_items        TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_website_tags         TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT ON `clients`.swp_websites             TO 'umanager'@'<IP_MANAGER>';

-- 3. Lecture / écriture
GRANT SELECT, INSERT, UPDATE, DELETE ON `clients`.swp_licenses_missing           TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT, INSERT, UPDATE, DELETE ON `clients`.swp_subscriptions_websites     TO 'umanager'@'<IP_MANAGER>';
GRANT SELECT, INSERT, UPDATE, DELETE ON `clients`.swp_vulnerabilities_management TO 'umanager'@'<IP_MANAGER>';

FLUSH PRIVILEGES;

-- Vérification
SHOW GRANTS FOR 'umanager'@'<IP_MANAGER>';
```

Les identifiants de connexion vont dans `config/config.local.php`
(`crm_db.*`), jamais dans ce dépôt.
