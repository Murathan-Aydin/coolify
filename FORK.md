# Fork Murathan-Aydin/coolify

Ce dépôt est un fork de [coollabsio/coolify](https://github.com/coollabsio/coolify) avec des modifications personnelles.
Ce document explique comment rester à jour avec la version officielle sans perdre ces modifications.

## Branches, remotes et versions

| Élément | Rôle |
|---|---|
| `main` | Code officiel + mes modifications. C'est la branche que je déploie. |
| `perso` | Branche de travail pour les nouvelles modifications, fusionnée ensuite dans `main`. |
| `upstream-sync` | Créée par le robot : contient la dernière fusion avec l'officiel, en attente de validation. |
| `origin` | Mon fork. `git push` envoie ici. |
| `upstream` | Le dépôt officiel, en lecture seule (le push est bloqué avec `no_push`). |

Les versions suivent la forme `v<version officielle>-mura.<n>`, par exemple `v4.5.4-mura.1`, puis `v4.5.4-mura.2`.
Après une fusion de la 4.5.5 officielle, on repart à `v4.5.5-mura.1`.
Ne jamais réutiliser un numéro officiel seul (`v4.5.5`) : il se confondrait avec la vraie version.

## Mise à jour automatique (GitHub Actions)

Le workflow `.github/workflows/mura-upstream-sync.yml` tourne toutes les 6 heures. On peut aussi le lancer à la main :
onglet **Actions** du fork, puis **Upstream sync (fork)**, puis **Run workflow**.
On ne peut pas le déclencher au moment exact d'un push officiel : GitHub ne prévient pas un fork quand le dépôt d'origine change.

| Situation | Ce qui se passe | Ce que je fais |
|---|---|---|
| Rien de nouveau | Rien. | Rien. |
| Nouveaux commits, sans conflit | Une PR « Sync with upstream Coolify » s'ouvre de `upstream-sync` vers `main`. | Je suis la section « Valider une mise à jour », puis je fusionne la PR. |
| Conflit | Le run échoue en rouge, GitHub m'envoie un e-mail et le résumé du run liste les fichiers en conflit. | Je suis la section « Conflits ». |

Le robot ne touche jamais `main` directement. Rien n'est déployé sans moi.

### Mise en place (une seule fois)
1. Dans l'onglet **Actions** du fork, cliquer sur « I understand my workflows, go ahead and enable them ».
2. Désactiver les workflows officiels, qui n'ont pas de sens sur un fork (builds d'images, release, bots).
   Il faut garder seulement le mien :
   ```bash
   gh workflow list --repo Murathan-Aydin/coolify --all --json name,path -q '.[] | select(.path | contains("mura-") | not) | .name' |
     while read -r wf; do gh workflow disable "$wf" --repo Murathan-Aydin/coolify; done
   ```
3. **Settings → Actions → General**, cocher « Allow GitHub Actions to create and approve pull requests ».
4. Recommandé : créer un token GitHub (fine-grained, droits *Contents*, *Pull requests* et *Workflows* en écriture sur ce dépôt),
   puis l'ajouter comme secret `SYNC_TOKEN`. Sans lui, la synchronisation échoue chaque fois que l'officiel modifie
   un fichier de `.github/workflows/`, car le token par défaut ne peut pas pousser ces fichiers.

## Valider une mise à jour (PR sans conflit)
```bash
cd ~/dev/coolify
git fetch origin
git switch upstream-sync && git pull
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker exec coolify php artisan migrate
docker exec coolify ./vendor/bin/pest tests/Feature/Team tests/Unit/Policies tests/Feature/TeamInvitationHandlingTest.php
```
Si les tests passent et que l'interface fonctionne sur http://localhost:8000, je fusionne la PR sur GitHub.
Ensuite, je crée la nouvelle version :
```bash
git switch main && git pull
git tag -a v4.5.5-mura.1 -m "Coolify 4.5.5 + mes modifications" && git push origin v4.5.5-mura.1
gh release create v4.5.5-mura.1 --repo Murathan-Aydin/coolify --generate-notes
```

## Conflits (mise à jour manuelle)
Il y a un conflit quand l'officiel et moi avons modifié les mêmes lignes.
```bash
cd ~/dev/coolify
git fetch upstream --tags
git switch main && git pull
git switch -c update-upstream
git merge upstream/main          # ou un tag : git merge v4.5.5
git status                       # liste les fichiers « both modified »
```
Pour chaque fichier en conflit, ouvrir les blocs `<<<<<<<` / `=======` / `>>>>>>>` et garder les deux intentions :
leur nouveau code et ma modification. Ensuite :
```bash
git add <fichiers>
git commit                       # termine la fusion
docker exec coolify php artisan migrate
docker exec coolify ./vendor/bin/pest tests/Feature/Team tests/Unit/Policies
git switch main && git merge update-upstream && git push origin main
git branch -d update-upstream
```
Pour abandonner en cours de route : `git merge --abort`.

## Règles pour limiter les conflits
- **Ajouter plutôt que modifier.** Un nouveau fichier ne crée jamais de conflit.
- **Si je dois toucher un fichier officiel,** je fais une modification courte, idéalement une ligne qui appelle mon propre code.
- **Front :** mes styles et composants vont dans des fichiers à moi (par exemple `resources/views/components/mura/`), je ne réécris pas une vue officielle entière.
- **Migrations :** je ne modifie jamais une migration officielle, j'en ajoute toujours une nouvelle.
- **Je mets à jour souvent :** 5 commits officiels se fusionnent facilement, 500 c'est pénible.
- **Je teste mes fonctionnalités :** après une fusion, des tests qui passent prouvent que mes modifications fonctionnent encore.

## Mes modifications (à vérifier après chaque mise à jour)
| Fonctionnalité | Fichiers principaux | Tests |
|---|---|---|
| Correctifs d'invitation | `app/Livewire/Team/InviteLink.php`, `app/Http/Controllers/Controller.php` | `tests/Feature/TeamInvitationHandlingTest.php` |
| Accès par projet (viewer / deployer) | `app/Policies/Concerns/ChecksProjectAccess.php`, policies Project/Environment/Application/Database/Service, `app/Livewire/Project/Members.php`, `app/Models/User.php` (`canAccessProject`), `app/Models/Project.php` (`ownedByCurrentTeam`) | `tests/Feature/Team/ProjectScopedMembersTest.php` |
| Invitation en masse | `app/Livewire/Team/InviteLink.php` (`bulkInvite`), `app/Models/TeamInvitation.php` (`attachUser`) | `tests/Feature/TeamInvitationHandlingTest.php` |

Fichiers officiels que je modifie, donc à surveiller en cas de conflit : `InviteLink.php`, `Controller.php`, `User.php`, `Project.php`,
`Team.php`, les 5 policies et les vues `team/invite-link`, `team/member`, `project/edit`, `project/database/heading`.

**Mises à jour automatiques de Coolify :** sur une instance installée depuis ce fork, les désactiver dans les réglages.
Sinon Coolify remplace mon code par l'image officielle.
