# Général
- L'esthétique doit être conséquent partout.
- Quand on affiche un site web, on retire le protocole (https://) et les "www." du début. Le but est que ce soit facile à lire. "https://www.rds.ca" deviendra "rds.ca". Cela sera vrai dans les affichage de texte, les dropdowns... mais évidemment pas dans les liens!

# Menu
- En premier, je veux avoir : Websites, Items, Clients, Products. Retirer le label "CRM"
- En second, "Monitoring" sera là. On renomme pour "Receptor". "Sites" deviendra "Extractions".
- En troisième, "Data". Tout reste ainsi.
- En quatrième, "Management" contiendra "Users".

# Users
- Liste des utilisateurs avec leur rôle.
- Je veux pouvoir : créer, éditer, supprimer, et suspendre suspendre un utilisateur.
- Les rôles seront définis dans un fichier .php comme défini plus tôt dans le projet.
- Je veux pouvoir manipuler : nom, prénom, adresse courriel et rôle.
- Le login se fera via Google Auth (lorsque j'aurai activé.)

# Datatables
- Le "Entries per page" sera toujours en bas du tableau.
- Tu dois me demander de spécifier sur quoi il est possible de trier et filtrer.
- Je voudrai avoir une section, au-dessus du table pour mettre mes filtres.
- On devra absolument cliquer sur "Search" (ou "Filter") pour que les résultats changent
- Si le nombre de résultat le requiert, on devra utiliser ajax pour ne pas charger 100% des rows. Valider avec moi à la construction ce qui est requis dans le doute.
- Les filtres doivent toujours modifiés l'URL de la page afin qu'on puisse y accéder.

# Clients - Liste
- Mettre la date de last sync en haut. En fait, je veux "50 minutes ago" et un tooltip contenant la date.
- Bâtir la section pour les filtres.
- Par défaut, on affiche les clients actifs.
- On fait toujours une requête pour valider si des subscriptions ne sont pas liés. Si c'est le cas, ça prend un avertissement en haut de la page avec un lien qui permet de changer le filtre

# Clients - detail
- La section "client" doit prendre moins d'espace. Considérer les données sur deux colonnes.
- Pour le "email", je veux un icône qui me permettra de le copier.
- Pour les champs, Teamwork Id, Hubspot Id et Blogvault client ID, je voudrai que la valeur soit cliquable et envoie vers ces systèmes. Ça me prendra un paramètre de URL pattern dans config.php
- Je veux avoir un "Edit" pour les champs dans clients. Ça enverra vers mon WordPress pour éditer directement à la source. J'aurai besoin d'une config de URL pattern pour "wordpress_edit_user". C'est un bouton pour la section au complet.
- Je veux avoir un "Edit" pour chaque ligne de subscription. Ça enverra vers mon WordPress pour éditer directement à la source. J'aurai besoin d'une config de URL pattern pour "wordpress_edit_subscription".

# Websites
- Revoir la section des filtres pour standardiser.
- Tags doit être un select2 multiple
- "Host" n'est pas pertinent
- "Clients" n'est pas pertinent
- Filtre : ajouter Connection (connected/disconnected)

# Website id
-Je veux avoir un "View in Blogvault" dans la section "website". Ça enverra vers mon compte blogvault pour éditer directement à la source. J'aurai besoin d'une config de URL pattern pour "blogvault_view_website".
- Je veux pouvoir savoir c'est qui le client (company, nom, prénom, courriel [avec icône copy]), 

Subscriptions : 
- retirer "Client", c'est rendondant.
- Next renewal: retirer l'heure
- Linked website : je voudrais que ce soit seulement afficher. Un peu avoir un icône pour edit et là, on affiche le dropdown.

---
# General
- Retirer "74 rules · 14 sources" dans le sidebar. La version affichée doit venir d'une configuration et non être hardcodée.
- Le breadcrumb dans la bande blanche dans le haut de chaque page est inutile. À retirer.


# Menu
- En premier, je veux avoir : Clients, Websites, Items, Products.

# Clients - Liste
- La notice des subscriptions not linked a l'air d'un "tag". Ça doit être l'équivalent d'une boite d'avertissement. On doit pouvoir diffiser une info (bleu), un avertissement (jaune/orange), un problème critique (rouge). Crée donc le code pour gérer ce genre d'affichage. Pour les subscriptions not linked, ça doit être un warning.
- La première colonne du tableau doit être "Company", puis "Contact" (Prénom + espace + nom). Si le "Company" n'est pas peuplé, on mettra la même chose que Contact.
- Pour le search, le style n'y ait pas du tout. Cette section doit afficher, au-dessus du datatable : search + dropdowns de filtre + bouton "Search". Le search doit chercher dans company, nom, prénom, email. Les filtres de dropdown doivent être "Status" contenant "All", "Active", "Inactive" commes valeurs et la valeur par défaut est Active, "Subscriptions" contenant "All", "Have unassigned", "Do not have unassigned" et la valeur par défaut est "All".

# Clients - detail
- Section "client" en haut. Tu gardes l'entête. Puis, colonne 1 : Company, Contact, email. Colonne 2: Teamwork ID, Hubspot ID, Blogvault Client ID. Le "Last synced" doit être sous la boite en mode "Last sync : x minutes ago" avec la date en tooltip.
- Section "Subscriptions" (changer le nom pour "Services") : Created, Last payment doivent seulement être la date (sans l'heure). Le crayon pour editer devrait être de l'autre sens, et plus gros. Quand aucun site n'est assigné, on devrait avoir "Unassigned" en rouge. Une notice devrait être présente dans le haut de la page pour mentionner que des services ne sont pas assigné. Je voudrais pouvoir avoir un filtre nommé "Website" au-dessus qui me permet de filtrer par site web en fonction du contenu de la colonne website. Je voudrais aussi avoir un search qui chercherait dans la colonne product.

# Software Catalogue
- Ajouter le choix "custom code" dans le dropdown des choix de licence
- La colonne et données "repo" ne fait aucun sens. À retirer.
- Retirer la directive en lien avec le CLI dans le bas de la page.
- Convertir cette liste en liste AJAX via Datatables. Rapidement, nous aurons des milliers d'entrées.
