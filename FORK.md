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

Le workflow `.github/workflows/mura-upstream-sync.yml` tourne toutes les 6 heures et ne réagit qu'aux nouvelles versions officielles (tags `vX.Y.Z`, ex. `v4.5.4`), pas aux commits isolés sur `main`. On peut aussi le lancer à la main :
onglet **Actions** du fork, puis **Upstream sync (fork)**, puis **Run workflow**.
On ne peut pas le déclencher au moment exact d'un push officiel : GitHub ne prévient pas un fork quand le dépôt d'origine change.

| Situation | Ce qui se passe | Ce que je fais |
|---|---|---|
| Pas de nouvelle version | Le run se termine en vert, sans e-mail. | Rien. |
| Nouvelle version, sans conflit | Les tests tournent, puis une PR « Sync with upstream Coolify » s'ouvre de `upstream-sync` vers `main`, avec le résultat des tests et la liste des commits de la version. Elle m'est assignée et ma review est demandée, donc je reçois un e-mail. Si une version plus récente sort avant que je fusionne, un commentaire me mentionne, et je reçois un nouvel e-mail. | Je suis la section « Valider une mise à jour », puis je fusionne la PR. |
| Conflit, résolu par Claude | Claude résout les conflits (voir « Résolution des conflits par Claude »), les tests tournent, puis la PR s'ouvre avec « (conflicts resolved by Claude) » dans le titre, la liste des fichiers et les notes de Claude. | Je relis surtout les fichiers listés, puis « Valider une mise à jour ». |
| Conflit, tests en échec | La PR s'ouvre en brouillon (draft) avec la fin du journal des tests, et le run passe en rouge (e-mail). | Je récupère la branche `upstream-sync` et je corrige, ou je repars de la section « Conflits ». |
| Conflit que Claude n'a pas résolu (ou pas de token Claude) | Le run échoue en rouge, GitHub m'envoie un e-mail et le résumé du run liste les fichiers en conflit. L'e-mail revient toutes les 6 h tant que ce n'est pas réglé. | Je suis la section « Conflits ». |

Le robot ne touche jamais `main` directement. Rien n'est déployé sans moi.

Les e-mails passent par les notifications GitHub. Il faut vérifier que **Settings → Notifications** (de mon compte GitHub) a bien
« Email » coché pour *Participating, @mentions and custom* et pour *Actions* (« Only notify for failed workflows »).

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
5. Pour que Claude résolve les conflits : sur mon Mac, lancer `claude setup-token` (il faut un abonnement Claude Pro,
   Max, Team ou Enterprise), puis ajouter le token affiché comme secret `CLAUDE_CODE_OAUTH_TOKEN`
   (**Settings → Secrets and variables → Actions → New repository secret**). Le token est valable un an : le refaire
   à l'expiration. Ne pas ajouter de secret `ANTHROPIC_API_KEY`, il passerait avant l'abonnement.

### Résolution des conflits par Claude
Quand la fusion d'une version officielle a des conflits, le workflow lance Claude Code (`anthropics/claude-code-action`,
modèle Opus, avec mon abonnement) dans la fusion en cours. Claude lit ce fichier, en particulier « Mes modifications » et
« Règles pour limiter les conflits », regarde les commits officiels de chaque fichier, résout, lance les tests des
fonctionnalités du fork, puis écrit ses notes en français. Elles arrivent dans la PR.

Pour que ce soit sans risque :
- Claude travaille dans un job qui ne peut que **lire** le dépôt. Il ne peut ni committer, ni pousser.
  Un second job, sans Claude, pousse `upstream-sync` et ouvre la PR. `main` n'est jamais modifiée sans moi.
- Les tests (`tests/Feature/Fork`, invitations, policies, accès officiels) tournent après la résolution. S'ils échouent, la PR
  reste en brouillon.
- Quand l'officiel livre une fonctionnalité qui fait la même chose qu'une des miennes, la consigne est de garder la
  version officielle et d'y adapter mon code. Claude le signale dans ses notes. C'est ce qui s'est passé à la 4.7 pour l'accès par projet.

La résolution consomme le quota de mon abonnement Claude (le job a une limite de 2 h).

## Valider une mise à jour (PR sans conflit)
```bash
cd ~/dev/coolify
git fetch origin
git switch upstream-sync && git pull
composer install && npm ci && npm run build
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Fork tests/Feature/TeamInvitationHandlingTest.php tests/Unit/Policies tests/Feature/Access
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker exec coolify php artisan migrate
```
Les tests tournent sur le Mac (PHP de Herd, SQLite en mémoire), **jamais dans un conteneur de dev** : leur
variable `DB_CONNECTION=pgsql` peut faire vider ma base de dev par les tests. Si `node_modules` appartient au conteneur
Vite de dev (binaires Linux), faire l'installation dans un `git worktree` à part.
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
php -d memory_limit=2G vendor/bin/pest --compact tests/Feature/Fork tests/Feature/TeamInvitationHandlingTest.php tests/Unit/Policies tests/Feature/Access
docker exec coolify php artisan migrate
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
| Correctifs d'invitation : nouveau lien de connexion pour un membre qui n'a jamais défini son mot de passe, messages d'erreur plus clairs | `app/Livewire/Team/InviteLink.php` (`isLockedOutMember`), `app/Http/Controllers/Controller.php` | `tests/Feature/TeamInvitationHandlingTest.php` |
| Invitation en masse (jusqu'à 100 adresses, même rôle et mêmes accès « Scopes » que l'invitation simple) | `app/Livewire/Team/InviteLink.php` (`bulkInvite`), `resources/views/livewire/team/invite-link.blade.php` | `tests/Feature/TeamInvitationHandlingTest.php` |
| Apparence : image de fond, Liquid Glass, thèmes Apple Dark / Apple Light | `resources/views/layouts/base.blade.php` (`applyBackgroundImage`, `applyGlass`, presets), `resources/views/components/theme-controls.blade.php`, `resources/css/app.css` | à vérifier à la main sur la page Appearance |
| Conversion des anciens rôles de projet en accès officiels (voir ci-dessous) | `database/migrations/2026_11_16_000000_convert_fork_project_roles_to_access_grants.php` | `tests/Feature/Fork/ForkAccessConversionTest.php` |

**Accès par projet et par ressource : c'est maintenant la fonctionnalité officielle.** Coolify 4.7 a ajouté les « scoped grants »
(**Team → Members → Scopes** : accès par équipe, projet, environnement ou ressource, niveaux Read, Read secrets, Deploy,
Write, Delete). Mon ancien système (viewer / deployer / manager, `project_user`, `resource_user`, `restricted_to_projects`)
a été retiré à la fusion de la v4.7.5. La migration du 2026-11-16 convertit les rôles existants : viewer → Read,
deployer → Deploy, manager → Write (sans Delete), et les invitations en attente limitées à un projet. Deux différences :
- Read ne montre pas les valeurs des secrets : pour qu'un ancien viewer les voie, lui ajouter « Read secrets » ;
- un rôle plus bas sur une seule ressource est converti ressource par ressource. Les ressources créées ensuite dans ce projet
  ne sont donc pas données automatiquement. Un membre non restreint garde la lecture de toute l'équipe : un « none » ne peut plus
  lui cacher une ressource. Ces cas sont écrits dans `storage/logs/laravel.log` (« Fork access conversion »).

Les anciennes tables restent en base, inutilisées, pour pouvoir vérifier la conversion.

Fichiers officiels que je modifie, donc à surveiller en cas de conflit : `InviteLink.php`, `Controller.php`, les vues
`team/invite-link`, `layouts/base`, `components/theme-controls` et `resources/css/app.css`.

## Installer le fork sur un serveur

À chaque push sur `main`, le workflow `.github/workflows/mura-image.yml` construit l'image
`ghcr.io/murathan-aydin/coolify:latest` (amd64 et arm64, environ 15 min). Sur le serveur, en root :

```bash
curl -fsSL https://install-coolify.m-aydin.fr | sudo bash
```

`install-coolify.m-aydin.fr` redirige (règle Cloudflare) vers `https://raw.githubusercontent.com/Murathan-Aydin/coolify/main/scripts/install-fork.sh`, qu'on peut utiliser directement si le domaine ne répond pas.

Le script écrit `/data/coolify/source/docker-compose.custom.yml` (le service `coolify` utilise mon image), lance
l'installateur officiel, puis désactive les mises à jour automatiques. Le script de mise à jour officiel garde ce
fichier, donc Coolify ne repasse jamais sur l'image officielle. Pour passer à la dernière image du fork, relancer
la même commande. Il marche aussi sur un serveur où Coolify officiel est déjà installé.

**Mises à jour automatiques de Coolify :** le script les désactive. Ne pas les réactiver : elles changent la version
affichée et redémarrent Coolify à chaque version officielle.
