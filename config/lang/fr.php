<?php
/**
 * Chaînes d'affichage françaises. Miroir de en.php — findings.json reste neutre.
 * Placeholders : {observed}, {threshold}, et données par règle (ex. {eol_date}).
 */

declare(strict_types=1);

return [
    'ui' => [
        'sites'          => 'Sites',
        'site'           => 'Site',
        'extraction'     => 'Extraction',
        'findings'       => 'Constats',
        'information'    => 'Informations',
        'search'         => 'Rechercher',
        'no_data'        => 'Aucune donnée pour l\'instant.',
        'received'       => 'Reçue',
        'status'         => 'Statut',
        'rule'           => 'Règle',
        'category'       => 'Catégorie',
        'severity'       => 'Sévérité',
        'observation'    => 'Observation',
        'legend'         => 'Légende des catégories',
        'eol'            => 'fin de vie',
        'supported_until' => 'supporté jusqu\'au',
        'passed'         => 'conforme',
        'not_applicable' => 'non applicable',
        'unknown'        => 'indéterminé',
        'compliant_hidden' => 'Règles conformes, non applicables et indéterminées',
    ],
    'status' => [
        'pass'    => 'Conforme',
        'fail'    => 'À corriger',
        'na'      => 'N/A',
        'unknown' => 'Indéterminé',
    ],
    'severity' => [
        'C' => 'Critique',
        'E' => 'Élevée',
        'M' => 'Moyenne',
        'I' => 'Info',
    ],
    'pastille' => [
        'green'  => 'Conforme',
        'orange' => 'Attention',
        'red'    => 'Critique',
        'blue'   => 'Info',
        'grey'   => 'N/A',
    ],
    'categories' => [
        'DOMAIN'      => 'Domaine',
        'SSL'         => 'SSL / TLS',
        'SECURITY'    => 'Sécurité',
        'HTTP'        => 'HTTP',
        'DNS'         => 'DNS',
        'EMAIL'       => 'Courriel',
        'PERFORMANCE' => 'Performance',
        'SEO'         => 'SEO',
        'UPDATES'     => 'Versions et mises à jour',
        'PHP'         => 'PHP',
        'DATABASE'    => 'Base de données',
        'HOSTING'     => 'Hébergement',
        'CRON'        => 'Cron',
        'USERS'       => 'Utilisateurs',
        'CACHE'       => 'Cache',
        'CONTENT'     => 'Contenu',
    ],
    'rules' => [
        // SSL
        'A1'  => ['title' => 'Certificat SSL valide', 'fail' => 'Le certificat SSL est expiré. Le renouveler immédiatement.', 'pass' => 'Le certificat SSL est valide.'],
        'A2'  => ['title' => 'Expiration du certificat non imminente', 'fail' => 'Le certificat expire dans {observed} jours. Vérifier le renouvellement automatique.', 'pass' => 'Le certificat est valide encore {observed} jours.'],
        'A3'  => ['title' => 'Chaîne de certification complète', 'fail' => 'La chaîne de certification est incomplète (intermédiaire manquant).'],
        'A4'  => ['title' => 'Nom d\'hôte couvert par le certificat', 'fail' => 'Le nom d\'hôte du site n\'est pas couvert par le certificat (CN/SAN).'],
        'A5'  => ['title' => 'Émetteur de confiance (pas auto-signé)', 'fail' => 'Le certificat est auto-signé : les navigateurs afficheront un avertissement.'],
        'A6'  => ['title' => 'TLS 1.0/1.1 obsolètes désactivés', 'fail' => 'Le serveur accepte encore {observed}. Désactiver TLS 1.0 et 1.1.', 'pass' => 'Seules les versions TLS modernes sont acceptées.'],
        'A8'  => ['title' => 'En-tête HSTS présent', 'fail' => 'L\'en-tête Strict-Transport-Security est absent.'],
        'A10' => ['title' => 'Redirection HTTP vers HTTPS', 'fail' => 'Le site répond en HTTP sans rediriger vers HTTPS. Ajouter une redirection 301.', 'pass' => 'Le HTTP est redirigé vers HTTPS.'],
        // HTTP
        'B1'  => ['title' => 'Compression du HTML', 'fail' => 'Le HTML est servi sans compression. Activer gzip ou Brotli.', 'pass' => 'Le HTML est compressé ({observed}).'],
        'B1b' => ['title' => 'Compression des assets statiques', 'fail' => 'Les assets statiques sont servis sans compression, contrairement au HTML.', 'pass' => 'Les assets statiques sont compressés ({observed}).'],
        'B2'  => ['title' => 'Compression Brotli', 'fail' => 'Brotli n\'est pas utilisé ({observed}) ; il compresse mieux que gzip.'],
        'B3'  => ['title' => 'HTTP/2 supporté', 'fail' => 'Le site est servi en HTTP/{observed}. Activer HTTP/2 accélère le chargement.', 'pass' => 'Le site est servi en HTTP/{observed}.'],
        'B6'  => ['title' => 'En-têtes de cache sur les assets', 'fail' => 'Les assets statiques ont un cache de {observed}s (attendu au moins {threshold}s).'],
        'B7a' => ['title' => 'X-Content-Type-Options: nosniff', 'fail' => 'L\'en-tête X-Content-Type-Options est absent.'],
        'B7b' => ['title' => 'Protection contre le clickjacking', 'fail' => 'Ni X-Frame-Options ni Content-Security-Policy ne sont présents.'],
        'B7c' => ['title' => 'Content-Security-Policy présent', 'fail' => 'Aucune Content-Security-Policy n\'est définie.'],
        'B7d' => ['title' => 'Referrer-Policy définie', 'fail' => 'L\'en-tête Referrer-Policy est absent.'],
        'B8'  => ['title' => 'Cookies sécurisés', 'fail' => 'Les cookies manquent d\'attributs de sécurité : {observed}.'],
        'B9'  => ['title' => 'Aucune divulgation de version serveur', 'fail' => 'Le serveur divulgue sa version : {observed}.'],
        // DNS
        'C1'  => ['title' => 'IPv6 (enregistrement AAAA)', 'fail' => 'Aucun enregistrement AAAA : le site n\'est pas joignable en IPv6.'],
        'C2'  => ['title' => 'Enregistrement CAA présent', 'fail' => 'Aucun enregistrement CAA : n\'importe quelle autorité peut émettre un certificat.'],
        'C5'  => ['title' => 'Chaîne de redirection courte', 'fail' => 'La chaîne de redirection est trop longue ou boucle ({observed}).'],
        'C6'  => ['title' => 'Temps de réponse (TTFB)', 'fail' => 'Le TTFB est de {observed} ms (seuil {threshold} ms).', 'pass' => 'Temps de réponse rapide ({observed} ms).'],
        'C7'  => ['title' => 'Site disponible', 'fail' => 'Le site répond un code HTTP {observed}.', 'pass' => 'Le site est disponible (HTTP {observed}).'],
        'C8'  => ['title' => 'Page 404 correcte', 'fail' => 'Une URL inexistante répond 200 au lieu de 404 (soft 404).'],
        'C9'  => ['title' => 'robots.txt présent et non bloquant', 'fail' => 'robots.txt est {observed}.', 'pass' => 'robots.txt est présent.'],
        'C10' => ['title' => 'sitemap référencé dans robots.txt', 'fail' => 'Aucun sitemap n\'est référencé dans robots.txt.', 'pass' => '{observed} sitemap(s) déclaré(s).'],
        // Courriel
        'D1'  => ['title' => 'Enregistrement SPF présent', 'fail' => 'Aucun enregistrement SPF : les courriels du site risquent d\'être rejetés.', 'pass' => 'Un enregistrement SPF est configuré.'],
        'D3'  => ['title' => 'DMARC avec politique active', 'fail' => 'DMARC est {observed}. Publier une politique (p=quarantine ou p=reject).', 'pass' => 'DMARC est actif (p={observed}).'],
        'D4'  => ['title' => 'Enregistrements MX résolvables', 'fail' => 'Aucun enregistrement MX : le domaine ne peut recevoir de courriel.'],
        // Domaine
        'W1'  => ['title' => 'Expiration du domaine non imminente', 'fail' => 'Le domaine expire dans {observed} jours. Le renouveler sans tarder.', 'pass' => 'Le domaine est valide encore {observed} jours.'],
        // Performance
        'PS1' => ['title' => 'Performance Lighthouse (mobile)', 'fail' => 'Score de performance mobile de {observed}/100 (seuil {threshold}).', 'pass' => 'Bon score de performance mobile ({observed}/100).'],
        'PS2' => ['title' => 'Accessibilité Lighthouse (mobile)', 'fail' => 'Score d\'accessibilité de {observed}/100 (seuil {threshold}).'],
        'PS3' => ['title' => 'SEO Lighthouse (mobile)', 'fail' => 'Score SEO de {observed}/100 (seuil {threshold}).'],
        'PS4' => ['title' => 'LCP sous le seuil', 'fail' => 'Le LCP mobile est de {observed} ms (seuil {threshold} ms).'],
        // Versions et mises à jour
        'F1'  => ['title' => 'Cœur WordPress à jour', 'fail' => 'WordPress {observed} n\'est pas à jour (disponible : {available}).', 'pass' => 'WordPress {observed} est à jour.'],
        'F2'  => ['title' => 'Branche WordPress supportée', 'fail' => 'WordPress {observed} est en fin de vie ({eol_date}). Migrer vers une branche supportée.', 'pass' => 'La branche WordPress est encore supportée.'],
        'F3'  => ['title' => 'Version PHP supportée', 'fail' => 'PHP {observed} n\'est plus supporté (fin de vie {eol_date}). Planifier une montée de version.', 'pass' => 'PHP {observed} est supporté (jusqu\'au {eol_date}).'],
        'F4'  => ['title' => 'Extensions à jour', 'fail' => '{observed} extension(s) ont une mise à jour disponible : {names}.', 'pass' => 'Toutes les extensions sont à jour.'],
        'F5'  => ['title' => 'Thèmes à jour', 'fail' => '{observed} thème(s) ont une mise à jour disponible.', 'pass' => 'Tous les thèmes sont à jour.'],
        'F7'  => ['title' => 'Prérequis des extensions respectés', 'fail' => '{observed} extension(s) exigent une version PHP/WP supérieure à l\'environnement : {names}.'],
        // PHP
        'G1'  => ['title' => 'memory_limit suffisant', 'fail' => 'memory_limit vaut {observed}, sous les 256M recommandés.', 'pass' => 'memory_limit vaut {observed}.'],
        'G4'  => ['title' => 'max_input_vars suffisant', 'fail' => 'max_input_vars vaut {observed} (recommandé au moins {threshold}).'],
        'G5'  => ['title' => 'Extensions PHP recommandées', 'fail' => 'Extensions PHP manquantes : {observed}.', 'pass' => 'Toutes les extensions PHP recommandées sont présentes.'],
        'G6'  => ['title' => 'OPcache actif', 'fail' => 'L\'extension OPcache n\'est pas chargée : les performances PHP en pâtissent.', 'pass' => 'OPcache est actif.'],
        // Base de données
        'H1'  => ['title' => 'Version de base de données supportée', 'fail' => '{observed} n\'est plus supporté (fin de vie {eol_date}). Planifier une montée de version.', 'pass' => 'La version de base de données est supportée (jusqu\'au {eol_date}).'],
        'H4'  => ['title' => 'Fragmentation des tables maîtrisée', 'fail' => 'Les tables cumulent {observed} octets d\'overhead (seuil {threshold}).'],
        'H5'  => ['title' => 'Transients expirés non accumulés', 'fail' => '{observed} transients expirés traînent en base (seuil {threshold}).'],
        'H9'  => ['title' => 'Préfixe de tables non standard', 'fail' => 'Le préfixe de tables est le défaut « wp_ » ; le changer complique les attaques automatisées.'],
        // Cache
        'I1'  => ['title' => 'Poids des options autoloadées', 'fail' => 'Les options autoloadées pèsent {observed} octets (seuil {threshold}).', 'pass' => 'Les options autoloadées sont dans le budget.'],
        'I4'  => ['title' => 'Cache objet persistant', 'fail' => 'Aucun cache objet persistant (Redis/Memcached) n\'est configuré.', 'pass' => 'Un cache objet persistant est configuré.'],
        // Cron
        'J2'  => ['title' => 'Aucun événement cron en retard', 'fail' => '{observed} événements cron sont en retard : WP-Cron ne s\'exécute probablement pas.', 'pass' => 'Aucun événement cron en retard.'],
        'J3'  => ['title' => 'Nombre d\'événements cron raisonnable', 'fail' => '{observed} événements planifiés (seuil {threshold}).'],
        // Sécurité
        'K1'  => ['title' => 'WP_DEBUG désactivé en production', 'fail' => 'WP_DEBUG est activé en production.', 'pass' => 'WP_DEBUG est désactivé.'],
        'K2'  => ['title' => 'WP_DEBUG_DISPLAY désactivé', 'fail' => 'WP_DEBUG_DISPLAY est activé : les erreurs PHP s\'affichent aux visiteurs.'],
        'K4'  => ['title' => 'Édition de fichiers désactivée', 'fail' => 'L\'éditeur de fichiers de l\'admin est actif : définir DISALLOW_FILE_EDIT à true.', 'pass' => 'L\'éditeur de fichiers de l\'admin est désactivé.'],
        'K6'  => ['title' => 'SSL forcé sur l\'admin', 'fail' => 'FORCE_SSL_ADMIN n\'est pas activé.', 'pass' => 'SSL est forcé sur l\'administration.'],
        // Hébergement
        'L1'  => ['title' => 'Espace disque libre suffisant', 'fail' => 'Il ne reste que {observed}% d\'espace disque libre (seuil {threshold}%).', 'pass' => '{observed}% d\'espace disque libre.'],
        'L4'  => ['title' => 'Dossier uploads inscriptible', 'fail' => 'Le dossier uploads n\'est pas inscriptible : les téléversements et mises à jour échoueront.'],
        'L5'  => ['title' => 'Cœur non inscriptible en production', 'fail' => 'Les fichiers du cœur sont inscriptibles par le serveur web : durcir les permissions.'],
        // Utilisateurs
        'M1'  => ['title' => 'Nombre d\'administrateurs maîtrisé', 'fail' => 'Le site compte {observed} administrateurs (seuil {threshold}).', 'pass' => 'Le site compte {observed} administrateur(s).'],
        'M2'  => ['title' => 'Aucun compte « admin » par défaut', 'fail' => 'Un compte administrateur utilise l\'identifiant par défaut « admin ».', 'pass' => 'Aucun compte « admin » par défaut.'],
        'BV1' => ['title' => 'Site non signalé comme piraté', 'fail' => 'BlogVault signale ce site comme piraté : {detections} détection(s) non résolue(s).', 'pass' => 'L\'analyse antimaliciel de BlogVault ne signale rien.'],
        'BV2' => ['title' => 'Aucune vulnérabilité connue', 'fail' => 'BlogVault recense {observed} vulnérabilités connues sur {components} composant(s).', 'pass' => 'BlogVault ne recense aucune vulnérabilité connue pour le cœur, les extensions ni les thèmes.'],
        'BV3' => ['title' => 'Sauvegarde récente et réussie', 'fail' => 'Aucune sauvegarde récente exploitable : dernier instantané « {observed} » (seuil {threshold} jours).', 'pass' => 'La dernière sauvegarde réussie date de {observed} jour(s).'],
        'BV4' => ['title' => 'Pare-feu actif en mode protection', 'fail' => 'Le pare-feu BlogVault n\'est pas en mode protection (actuellement : {observed}).', 'pass' => 'Le pare-feu est actif en mode protection.'],
        'BV5' => ['title' => 'Analyse antimaliciel récente', 'fail' => 'La dernière analyse antimaliciel date de {observed} jour(s) (seuil {threshold}).', 'pass' => 'La dernière analyse antimaliciel date de {observed} jour(s).'],
        'BV6' => ['title' => 'Authentification à deux facteurs des administrateurs', 'fail' => '{observed} administrateur(s) sur {administrators} n\'ont pas d\'authentification à deux facteurs.', 'pass' => 'Tous les administrateurs ont l\'authentification à deux facteurs activée.'],
        'WF1' => ['title' => 'Aucune vulnérabilité connue (Wordfence)', 'fail' => 'Wordfence Intelligence recense {observed} vulnérabilités connues sur {components} composant(s).', 'pass' => 'Wordfence Intelligence ne recense aucune vulnérabilité connue pour le cœur, les extensions ni les thèmes.'],
    ],
];
