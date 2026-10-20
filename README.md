# Securetxt — v0.3.4

Notes chiffrées en mode texte, en PHP pur (aucun serveur), pilotable à 100 % au clavier, avec la souris en plus.

## Lancer

**Windows** : ouvre `securetxt.bat`, mets le chemin de ton `php.exe` 8.5 sur la ligne `PHP85=`, puis double-clique dessus.
Pour avoir une icône sur le Bureau : double-clique sur `creer-raccourci.bat` (ou Paramètres > Créer un raccourci).
Si Windows Terminal est installé, le raccourci l'utilise (meilleur rendu).

**Linux** : `./securetxt.sh` (ou `PHP85=/chemin/vers/php ./securetxt.sh`).

Extensions requises : `sodium`, `mbstring`, et `ffi` sous Windows.

**Reprendre ton coffre d'une version précédente** : copie le dossier `coffre/` de l'ancien dossier dans celui-ci. Le format n'a pas changé.

## Paramètres (Ctrl+P, ou P dans l'arborescence)

Tout s'enregistre immédiatement dans `coffre/settings.json`. Ce fichier n'est pas chiffré, mais il ne contient que des réglages.

- **Thème** : Smile Basic, Nuit, Ambre, Phosphore vert, Papier, plus les thèmes que tu as enregistrés (marqués « perso »).
- **Couleurs personnalisées** : chaque élément (texte, liens, barres, sélection…) se règle séparément. ←/→ ±1, Maj+←/→ ±10, Entrée pour taper un numéro de 0 à 255, R pour annuler la modification d'un élément.
- **Enregistrer un thème** : dans l'écran des couleurs, **S** enregistre les couleurs actuelles sous le nom de ton choix. Le thème s'ajoute à la liste des thèmes. Pour le modifier, choisis-le, change les couleurs, puis S avec le même nom. Pour le supprimer, mets-le en surbrillance sur la ligne « Thème » et appuie sur **Suppr**. Changer de thème sans enregistrer perd les modifications en cours (l'appli demande confirmation).
- **Éditeur** : retour à la ligne automatique, numéros de ligne, indentation automatique, largeur des tabulations.
- **Souris** : activée ou désactivée.
- **Tous les raccourcis clavier** : Entrée remplace, + ajoute une touche, Suppr efface, Retour arrière rétablit la touche par défaut.

## Raccourcis par défaut de l'éditeur

| Touche                       | Action |
|------------------------------|--------|
| Ctrl+← / Ctrl+→              | mot précédent / suivant |
| Ctrl+↑ / Ctrl+↓              | ligne réelle précédente / suivante (↑ ↓ suivent les lignes affichées quand le texte revient à la ligne) |
| Maj+flèches, Maj+Début/Fin   | sélectionner |
| Ctrl+Maj+← / →               | sélectionner mot par mot |
| Ctrl+Maj+↑ / ↓               | sélectionner ligne entière par ligne entière |
| Ctrl+A                       | tout sélectionner |
| Ctrl+C / Ctrl+X / Ctrl+V     | copier / couper / coller (sans sélection : la ligne entière) |
| Ctrl+Z / Ctrl+Y              | annuler / rétablir |
| Ctrl+Retour arr. (ou +Maj)   | effacer le mot à gauche |
| Ctrl+Suppr (ou +Maj)         | effacer le mot à droite |
| Maj+Entrée                   | sauter une ligne au début de la ligne (le texte descend d'une ligne) |
| Ctrl+Entrée                  | nouvelle ligne en dessous, sans couper la ligne |
| Tab / Maj+Tab                | indenter / désindenter (plusieurs lignes si sélection) |
| Ctrl+K                       | supprimer la ligne |
| Alt+Z                        | retour à la ligne automatique oui/non |
| Ctrl+S                       | enregistrer (aussi automatique en sortant) |
| Ctrl+O                       | ouvrir le `[[lien]]` sous le curseur (le crée s'il n'existe pas) |
| Ctrl+B                       | rétroliens |
| Ctrl+F                       | recherche dans tout le coffre |
| Échap                        | revenir à la note parente, puis à l'arborescence |

## Souris

- **Arborescence** : clic pour sélectionner, double-clic pour ouvrir, clic sur `[-]`/`[+]` pour plier, molette pour défiler.
- **Éditeur** : clic pour placer le curseur et glisser pour sélectionner. Double-clic sélectionne un mot, triple-clic une ligne. Maj+clic étend la sélection, Ctrl+clic ouvre un `[[lien]]`. La molette fait défiler.
- **Barre du bas** : chaque touche affichée est un bouton.
- Les numéros de ligne ne sont jamais copiés : la sélection ne porte que sur le texte.

## Import / export (arborescence)

- **I** importe un fichier ou un dossier entier (sous-dossiers compris) dans le dossier sélectionné. Tab complète le chemin, et on peut aussi glisser le fichier dans la fenêtre. Les fichiers non texte sont ignorés et l'ancien encodage Windows (ANSI) est converti. **Les originaux restent en clair sur le disque** : supprime-les toi-même.
- **e** exporte la note ou le dossier sélectionné en `.txt`, **E** exporte tout le coffre. Les fichiers exportés ne sont **pas chiffrés**.

## Bon à savoir

- **Windows Terminal** garde pour lui Ctrl+Maj+↑/↓ (défilement). Pour les utiliser dans Securetxt, retire ces deux raccourcis dans les paramètres de Windows Terminal, ou choisis d'autres touches dans Ctrl+P.
- **Linux** : Maj+Entrée ne fonctionne que dans les terminaux qui le distinguent d'Entrée (kitty, foot, WezTerm, Ghostty…). Ailleurs, change la touche dans Ctrl+P.
- **Presse-papiers Linux** : `xclip`, `xsel` ou `wl-clipboard` permettent de partager le presse-papiers avec les autres applications.

## Arborescence : notes parentes et enfants

Une note qui contient un lien `[[...]]` devient le **parent** de la note visée (son **enfant**).

```
[-] Idées/
   ▼  amélioration
        |- ▼ exempledeenfant1
               |- enfantdeenfant1
        |- mail
   ►  autre note
```

- `►` devant une note : elle a des enfants, cachés. `▼` : ils sont affichés.
  (`[+]` / `[-]` reste réservé aux dossiers.)
- **Clavier** : **Espace** affiche / cache les enfants, **→** les affiche, **←** les cache ou remonte à la note parente.
  La barre du bas rappelle la touche quand une note avec enfants est sélectionnée.
- Souris : clic sur `►` / `▼`.
- Couleurs (Ctrl+P > Couleurs) : le symbole `►` / `▼` et les noms des notes enfants dans l'arborescence se règlent séparément de la couleur des `[[liens]]` dans le texte des notes.
- Tout est fermé au lancement de l'application. En revenant d'une note (Échap), les branches qui y mènent s'ouvrent.
- Une note qui a un parent **dans le même dossier** n'est plus listée seule : elle apparaît seulement sous son parent.
  Si son parent est dans un autre dossier, elle reste aussi listée dans son propre dossier.
- Une note liée par plusieurs notes apparaît sous chacune d'elles. Boucle (A → B → A) : marquée `↺`.
- Les images et les liens vers des notes pas encore créées ne sont pas affichés comme enfants.

## Format du coffre (inchangé depuis la v0.1)

```
coffre/
  vault.meta         sel + paramètres Argon2id + bloc de vérification (rien de secret)
  index.ltx          arborescence chiffrée (les noms des notes sont cachés)
  notes/n<id>.ltx    une note chiffrée par fichier, nom aléatoire
  settings.json      réglages (en clair, aucune donnée personnelle)
```

Clé dérivée du mot de passe avec Argon2id, chiffrement XSalsa20-Poly1305 (libsodium).
**Il n'y a aucun moyen de récupérer les notes si le mot de passe est perdu.**
