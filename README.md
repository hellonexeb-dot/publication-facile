# Publication Facile

Extension WordPress gratuite, compatible Elementor gratuit : publier des articles construits avec Elementor en remplissant un formulaire simple (titre, résumé, texte, image), à partir d'un article modèle.

## Installation

Téléchargez le fichier ZIP de la [dernière version](https://github.com/hellonexeb-dot/publication-facile/releases/latest), puis dans WordPress : **Extensions > Ajouter une extension > Téléverser une extension**.

Les versions suivantes sont ensuite proposées dans **Tableau de bord > Mises à jour**, comme pour les autres extensions.

## Publier une nouvelle version

1. Changer le numéro de version dans `publication-facile/publication-facile.php` (en-tête et `PF_VERSION`) et dans `readme.txt`.
2. `python build.py` crée `dist/publication-facile-<version>.zip`.
3. Créer une version GitHub avec l'étiquette `v<version>` et y joindre ce ZIP.

## Licence

GPL-2.0-or-later
