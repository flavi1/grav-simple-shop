> **Note du 2026-10-14** : le plancher Darcos et `legal_floor` décrits ci-dessous sont remplacés par les **contraintes globales**
> (`ShippingConstraints`, bloc `constraints` de `carriers.yaml`, voir le README de simple-shop) ; ce document reste l'historique de la conception.

> **Mise à jour du 2026-10-13 (soir)** — réponses du propriétaire intégrées :
> grilles et marges saisies **HT** (la TVA s'ajoute automatiquement, §1 : conversion avant l'offre et le plancher) ; plancher Darcos = première *règle de contrainte*
> d'une liste flexible (conditions → minimum), seuil sur les livres seuls ; retrait sur place **désactivé** en attendant une distance maximale ; Q14 accepté
> (plancher aussi sur les points de retrait) ; **déjà livré dans le cœur** : prix HT + marge, emballage, numéro de suivi (voir `DONE.md`). Reste : contrat/plugin Colissimo,
> points de retrait, administration par cartes + simulateur, règles flexibles (voir `TODO.md`).

# Conception — Transporteurs (contrat du cœur, plugin Colissimo, administration)

> Version 2 du 2026-10-13, qui intègre vos réponses Q1 à Q8 (`OBSCURE.md`), la loi Darcos et la gestion des taxes.
> **Rien n'est développé** : ce document sert à valider le contrat entre `simple-shop` et les plugins
> transporteur, puis le plugin Colissimo. Les points qui attendent encore votre avis sont les questions
> **Q10 à Q15** de `OBSCURE.md`.

## 0. Décisions prises (rappel)

| Sujet | Décision |
|---|---|
| Fournisseur | Compte professionnel Colissimo « Facilité » (test, puis probablement le client final). **Pas d'agrégateur.** |
| Points de retrait | Pris en charge, activables / désactivables dans l'administration. |
| Prix facturé au client | Tarif La Poste repris tel quel **+ marge** (fixe et/ou en %). Le suivi à 0,50 € de la lettre verte se traduit par une marge fixe de 0,50 €. Pas d'options (signature, assurance, suivi) : tout passe par la marge. |
| Lettre verte | Reste un mode **manuel** (`carriers.yaml`) ; ses tarifs et limites se règlent dans l'administration. |
| Grille tarifaire | Surchargeable dans l'administration ; offre gratuite (ou 0,01 € TTC) à partir d'un montant. |
| Loi Darcos | Plancher légal : 3,00 € TTC sous 35 € TTC de livres, 0,01 € TTC au-delà. **Livré** (voir §2), affiché en tête de l'écran Transporteurs, au-dessus du champ « seuil ». |
| Suivi de colis | Numéro de suivi saisi dans l'administration, repris dans l'e-mail « expédiée ». Dès la première version. |
| TVA | Livres et frais de livraison : 5,5 %. Prix produits saisis **HT**. Types de taxes réglables. **Livré** (voir §3). |
| `carriers.yaml` | Reste, comme « mode de gestion étendu » : modes manuels et contraintes légales/commerciales. |

## 1. Chaîne de calcul du prix de livraison (cible)

Pour un mode donné, dans cet ordre :

```
1. coût transporteur     grille du plugin (ou surcharge de l'administration) ; HT ou TTC selon la grille
2. + marge               prix = coût × (1 + marge %) + marge fixe     ex. lettre verte : + 0,50 € fixe
3. conversion en TTC     TaxManager (5,5 %) si la grille est HT
4. règle commerciale     « offert / 0,01 € à partir de X € de panier » (par mode)
5. plancher légal        Darcos : max(prix, 3,00 €) sous 35 € ; max(prix, 0,01 €) à partir de 35 €   [fait]
6. arrondi au centime    prix TTC affiché, encaissé, facturé
```

- Les étapes 4 et 5 sont **indépendantes du transporteur** : elles vivent dans le cœur (`ShippingPolicy`), donc un nouveau
  plugin transporteur en profite sans rien écrire. L'étape 5 est déjà en place (`LegalShippingFloor`).
- L'étape 5 ne s'applique pas à un mode marqué `legal_floor: false` (retrait sur place : pas une livraison). Choix à valider
  par votre conseil (Q12).
- La marge fixe + la marge en % remplacent les « options » : pas de case « suivi », pas de signature.

## 2. Loi Darcos — ce qui a été vérifié (2026-10-13)

| Point | Constat | Sources |
|---|---|---|
| Texte | Loi n° 2021-1901 du 30 décembre 2021 (« loi Darcos »), qui modifie l'article 1er de la loi Lang du 10 août 1981 (« sans pouvoir offrir ce service à titre gratuit »). Arrêté du 4 avril 2023 sur le montant minimal. Application depuis le **7 octobre 2023**. | [Cabinet Isabelle Barthet](https://www.isaurebarthet-avocats.com/actualites/articles/montant-minimal-des-frais-de-livraison-de-livres-instaure-par-la-loi-darcos-le-conseil-d-etat-rejette-la-requete-d-amazon), [Syndicat de la librairie](https://guide.syndicat-librairie.fr/prix-unique-du-livre/frais-de-port) |
| Montants | **3 € TTC** pour une commande de livres neufs **inférieure à 35 € TTC** ; **0,01 €** à partir de 35 €. Le Conseil d'État a rejeté le recours d'Amazon. | idem |
| Exclusion | Commande retirée dans un commerce de détail de livres. Les points relais ne sont pas mentionnés comme exclus. | idem |
| Ce qui n'est pas précisé | Si le seuil porte sur le seul prix des livres (hors frais), le traitement exact des commandes mixtes, l'application à un **éditeur qui vend en direct** (la loi vise les vendeurs à distance de livres), la situation exacte à 35,00 €. Sanctions non précisées par ces sources. | — |
| Notre interprétation (réglable) | Seuil comparé au prix des livres hors frais ; **strictement inférieur à 35 € → 3 €**, 35,00 € et plus → 0,01 €. | `delivery.book_law` |

**Elle s'applique très vraisemblablement ici** (vente à distance de livres neufs à des particuliers, livraison payante),
mais je ne suis pas juriste : à confirmer auprès du Syndicat national de l'édition ou de Légifrance avant la mise en production.
Le plugin est donc **réglable et désactivable** (seuil, 3 €, 0,01 €).

## 3. Taxes — ce qui est livré

- Prix des fiches produit saisis **HT** (jusqu'à 4 décimales : un prix public de 12,95 € TTC vaut 12,2749 € HT à 5,5 %).
- `TaxManager` : types de taxes (`TVA_5_5`, `TVA_20`, ajout possible), un code **pour tout le site**, conversion HT → TTC au centime.
- Le panier, le paiement, les commandes et les factures restent en TTC (aucun changement de format).
- Les 22 prix des livres existants ont été convertis (commit séparé, à relire).
- Limite assumée : un seul taux pour les produits et la livraison. Un taux par produit ou par ligne de frais de port demanderait
  une facture multi-taux (Factur-X par ligne) : non demandé pour l'instant (livres et livraison à 5,5 %).

## 4. Contrat entre `simple-shop` et un plugin transporteur

### 4.1 Principes
1. Le cœur ne connaît aucun transporteur ; il connaît un **contrat** (interface PHP) et des **événements**.
2. Un plugin transporteur **fournit** : ses modes, ses grilles de coût, ses contraintes, et (option) la recherche de points de retrait.
3. Le cœur **possède** : la politique de prix client (marge, offert dès X €), le plancher légal, les taxes, le tunnel, le stockage de la commande.
4. Chaque plugin gère sa propre configuration (`config/plugins/<plugin>.yaml`) ; le cœur la lit par le contrat, jamais directement.
5. Côté client : **zéro JavaScript** (sélection du point de retrait incluse). JavaScript seulement en administration.

### 4.2 Enregistrement (même mécanique que les passerelles de paiement)
Événement `onSimpleShopRegisterCarriers` : `$event['carriers'][$provider->getId()] = $provider`.
Abonnement **statique** dans `getSubscribedEvents()` et autoloader PSR-4 déclaré dans le **constructeur** du plugin
(leçon du correctif du jour : le plugin `mvc` déclenche ses événements avant le `onPluginsInitialized` des autres plugins).

### 4.3 Interface (proposition)

```php
interface CarrierProviderInterface
{
    public const API_VERSION = 1;                 // le cœur refuse un plugin d'une version incompatible

    public function getId(): string;              // 'colissimo'
    public function getLabel(): string;           // 'Colissimo'

    /** @return CarrierMode[] modes proposés (leur état actif/inactif vient de la configuration du plugin) */
    public function getModes(): array;

    /**
     * Coût du transporteur pour ce mode, en euros, et sa base ('ht'|'ttc'). null = mode non éligible.
     * @param array $metrics  ['weight' => g, 'thickness' => mm, 'subtotal' => € TTC, 'items' => n]
     */
    public function quote(string $modeKey, string $zone, array $metrics): ?CarrierQuote;

    /** Points de retrait proches de l'adresse (modes qui l'exigent). Peut lever CarrierUnavailable. */
    public function findPickupPoints(string $modeKey, array $address, array $metrics): array;

    /** Description des réglages pour l'écran d'administration (voir §6). */
    public function getAdminSchema(): array;
}

final class CarrierMode  { key, name, description, zones[], constraints{max_weight,max_thickness}, requires_pickup_point, enabled }
final class CarrierQuote { cost, basis('ht'|'ttc'), source('grille 2026-01-01' | 'surcharge'), valid_from }
final class PickupPoint  { id, name, address[], postal_code, city, distance_m, latitude, longitude, hours[], network, max_weight }
```

Règles du contrat :
- `quote()` est **pure et rapide** (aucun appel réseau) : elle est exécutée pour chaque mode à chaque affichage de la livraison.
- `findPickupPoints()` est le **seul** appel réseau ; le cœur met le résultat en cache de session (clé : code postal + poids) et gère
  l'échec : si le service est indisponible, le mode « point de retrait » est masqué et un message l'explique ; les autres modes restent proposés.
- Le plugin ne calcule ni marge, ni gratuité, ni Darcos, ni TVA.
- Les modes d'un plugin ont une clé unique `<provider>.<mode>` (ex. `colissimo.domicile`), pour ne pas entrer en collision avec `carriers.yaml`.

### 4.4 Événements

| Événement | Émetteur → écouteur | Charge utile | Rôle |
|---|---|---|---|
| `onSimpleShopRegisterCarriers` | cœur → plugins | `carriers` (par référence) | enregistrer un fournisseur |
| `onSimpleShopFindPickupPoints` | cœur → plugins | `mode_key`, `address`, `metrics`, `points` (par référence), `error` | **sélection d'un point de retrait** ; le plugin concerné remplit `points`. Permet à un plugin tiers de fournir des points sans implémenter toute l'interface |
| `onSimpleShopShippingSelected` | cœur → plugins | `order` (client, mode, prix, point) | notifier un plugin du choix (réservation, journal…) |
| `onSimpleShopOrderStatusChanged` | existe | `order_reference`, `old_status`, `new_status`, `changed_by` | inchangé |
| `onSimpleShopTrackingNumberSet` | cœur → plugins | `order_reference`, `tracking_number`, `carrier` | e-mail « expédiée », lien de suivi |

### 4.5 Données stockées dans la commande (`shipping`)

```
{ key, provider, mode, name, description,
  price,                      // TTC final, ce que paie le client (seul champ lu par le paiement et la facture, inchangé)
  cost, margin, price_before_floor, floor_applied,   // détail pour l'administration et la compta
  pickup_point: { id, name, address, postal_code, city, network },   // si applicable
  tracking_number, tracking_url }                    // renseignés à l'expédition
```
`price` reste le seul champ contractuel avec le reste du tunnel : aucun changement pour Stripe, la facture, les e-mails existants.

### 4.6 Tunnel client (sans JavaScript)
1. Étape livraison : liste de modes (radios) avec prix TTC, comme aujourd'hui. Un mode « point de retrait » indique « choix du point à l'étape suivante ».
2. Si le mode exige un point : sous-étape sur la même URL — liste de radios (nom, adresse, distance, horaires), formulaire GET « autre code postal ». Pas de carte.
3. Récapitulatif et paiement : mode + point affichés. La commande conserve le point choisi.
4. Expédition (administration) : saisie du numéro de suivi ; passage « Expédiée » → e-mail avec le lien.

## 5. Plugin `simple-shop-carrier-colissimo`

### 5.1 Modes
| Mode | Clé | Contraintes | Coût |
|---|---|---|---|
| Colissimo domicile (sans signature, jusqu'à 5 kg ; contre-signé au-delà) | `colissimo.domicile` | ≤ 30 kg | grille « en ligne » 2026 ou celle du contrat |
| Colissimo point de retrait | `colissimo.retrait` | ≤ 5 kg selon la grille 2026 | grille 2026 (− 0,70 € vs domicile) ; **exige un point** |

La lettre verte reste un mode manuel de `carriers.yaml` (grille 2026 ci-dessous), avec marge fixe de 0,50 € pour le suivi.

### 5.2 Grilles (recherche du 2026-10-13 sur laposte.fr et colissimo.entreprise.laposte.fr)
- **Il y a bien deux familles de tarifs Colissimo.**
  - *Public / affranchissement* (pages « tarif-colissimo », identiques pour particuliers et professionnels, « prix nets » 2026, TVA non précisée) :
    domicile 250 g 5,49 — 500 g 7,59 — 750 g 9,29 — 1 kg 9,59 — 2 kg 11,19 — 5 kg 17,39 — 10 kg 25,29 — 15 kg 31,99 — 30 kg 39,59 ; point de retrait 4,79 — 6,89 — 8,59 — 8,89 — 10,49 — 16,69 (≤ 5 kg). Suivi inclus.
  - *Contrat entreprise* (Facilité ou Privilège, grille **HT**, document « Tarifs 2025 » du 15/11/2024, aucune grille 2026 trouvée) : domicile sans signature
    250 g 6,72 — 500 g 7,57 — 750 g 8,45 — 1 kg 9,17 — 2 kg 10,29 — 3 kg 11,29 — 5 kg 13,30 — 10 kg 17,85 — 20 kg 27,13 — 30 kg 36,39 ; avec signature + 1,05 ;
    point de retrait 5,11 — 5,95 — 6,85 — 7,57 — 8,68 — 9,68 — 11,69 (5 kg) — 16,23 (10 kg) ; supplément « non mécanisable » 6,50 € HT. Suivi professionnel inclus.
    Le contrat est plus cher que le tarif public pour les petits colis (250 g : 6,72 € HT contre 5,49 €) et moins cher au-delà de 2 kg environ.
  - Rien n'oblige une petite maison d'édition à passer par le contrat : le tarif public reste utilisable (affranchissement en ligne ou au guichet). Le contrat sert surtout à l'outil
    d'expédition (ColiShip), au suivi professionnel et, si Colissimo l'ouvre, aux services web (étiquettes, points de retrait). C'est un choix de gestion, pas une obligation.
- **Lettre verte pour les professionnels : oui** (« lettre verte », « lettre verte suivie », Écopli, recommandée…), mêmes « prix nets » 2026 que le public : 20 g 1,52 — 100 g 3,10 —
  250 g 5,24 — 500 g 7,41 — 1 kg 9,29 — 2 kg 11,14 ; **suivi : pas avec la lettre verte simple ; la lettre verte suivie ajoute exactement 0,50 €** (2,02 / 3,60 / 5,74 / 7,91 / 9,79 / 11,64), jusqu'à 2 kg.
- **Suivi** : inclus dans tous les Colissimo (public et contrat) ; payant (0,50 €) seulement pour le courrier (lettre verte).
- **Valeur de départ retenue** : grille Facilité 2025 HT pour Colissimo domicile (la seule explicitement HT, et c'est le contrat ouvert), lettre verte suivie 2026 pour les petits colis.
  À remplacer par la grille 2026 de votre espace (Q10). La Poste annonce une hausse de la lettre verte au 1er janvier 2027 (timbre vert à 1,67 €).
- **Emballage** (Q4) : aucune source fiable trouvée pour le poids d'une enveloppe à bulles ou d'un carton plat de livre ; valeurs de départ **40 g** et **+ 6 mm** d'épaisseur, **réglables**, à remplacer par une pesée.

### 5.3 Mise à jour des tarifs (Q6)
Aucune API de tarifs n'existe chez La Poste : on combine (A) la grille datée livrée avec le plugin (renouvelée chaque janvier) et
(B) la **surcharge dans l'administration** (ligne par ligne, ou grille de votre contrat). La surcharge est visible comme telle
(« surcharge ») et n'est jamais écrasée par une mise à jour du plugin. Bandeau d'alerte si la grille n'a pas été vérifiée depuis plus d'un an.

### 5.4 Points de retrait (Q1)
- Service web La Poste `findRDVPointRetraitAcheminement` (SOAP, aussi appelable en GET) : `accountNumber` + `password` ou `apikey`, `zipCode`, `city`, `countryCode`, `weight` (g), `shippingDate`, `filterRelay` ([spécification](https://www.colissimo.entreprise.laposte.fr/sites/default/files/2021-04/spec_ws_livraison_en.pdf)).
- **Point à vérifier d'urgence** : la page du contrat Facilité ne mentionne aucune API. Il faut vérifier dans l'espace Colissimo Entreprise que le compte donne bien des
  identifiants pour les **services web** (sinon, les demander à Colissimo ou prendre l'offre qui les inclut). C'est le préalable des points de retrait (Q10).
- Appel par `curl` + enveloppe SOAP écrite à la main (évite de dépendre de l'extension PHP `soap`) ; délai d'attente court (3 s) ; cache de session ; journal des erreurs.
- Identifiants dans `config/plugins/simple-shop-carrier-colissimo.yaml` (jamais dans le dépôt) ; contrôle de connexion depuis l'administration.

## 6. Administration (Admin2, onglet « Transporteurs »)

De haut en bas :
1. **Encart légal** (Darcos) : « Frais minimum : 3,00 € TTC sous 35,00 € ; 0,01 € TTC au-delà » avec le champ « seuil minimum » en dessous
   (réglage actuel : Plugins > Simple Shop > Livraison ; l'encart de lecture est déjà livré, l'édition directe suivra).
2. **Une carte par fournisseur** (Colissimo, « Manuel / carriers.yaml ») et, dedans, **une ligne par mode** : interrupteur actif/inactif (le minimum demandé),
   marge fixe (€) et marge (%), « offert dès X € de panier », mention « plancher légal appliqué / non appliqué ».
3. **Grille de coût** du mode : tableau (poids → coût), date de validité, indicateur « grille officielle » ou « surcharge », bouton « surcharger cette ligne ».
4. **Simulateur** : poids, épaisseur, code postal, montant de livres → prix par mode, avec le détail de la chaîne du §1 (coût, marge, offre, plancher légal, TVA). Outil
   de contrôle indispensable après chaque mise à jour de grille.
5. **YAML avancé** (existant) pour les modes manuels.
Les formulaires sont générés par `getAdminSchema()` : un nouveau plugin apparaît sans modifier la page.
Les commandes affichent mode, point de retrait, champ « numéro de suivi » et le lien de suivi.

## 7. Phases

| Phase | Contenu | Prérequis |
|---|---|---|
| 0 — fait | Taxes HT, plancher Darcos, correctif d'autoload | — |
| 1 — cœur | Contrat + événements + fournisseur « manuel » (= `carriers.yaml` actuel) ; chaîne de prix (marge, offre) ; `shipping` enrichi ; numéro de suivi + e-mail ; tests | réponses Q11–Q15 |
| 2 — Colissimo sans retrait | Plugin, grilles 2026, surcharges, administration par cartes, simulateur | accès aux fichiers de config du serveur (§8) |
| 3 — points de retrait | Service web, sous-étape sans JavaScript, stockage | identifiants services web (Q10) |
| 4 — finitions | Grille de contrat, alerte d'ancienneté, lettre verte paramétrée dans l'administration | tarif du contrat |

## 8. Accès aux fichiers de configuration du serveur
Voir `TODO.md` (« Accès au serveur »). Mon environnement n'a **aucune sortie FTP ni SSH** (réseau limité à HTTPS via un proxy filtré) : un FTP
ne pourrait donc pas fonctionner, même avec des identifiants. La méthode proposée passe par GitHub Actions, avec les secrets SSH déjà
configurés pour le déploiement.

## 9. Risques
- Services web Colissimo inclus ou non dans le contrat Facilité (Q10) ; pas d'environnement de test connu : les appels réels ne seront testables que chez vous.
- Interprétation exacte de la loi Darcos (§2) et traitement du retrait sur place.
- Tarifs publics ≠ tarifs du contrat ; TTC vs HT des grilles (Q11).
- Dépendance aux extensions PHP du serveur (curl, éventuellement soap) : à vérifier sur OVH.
- Non testable depuis l'environnement de développement : appels Colissimo, Admin2, tunnel complet ; seuls le calcul, les contrats et les formats sont testés en ligne de commande.
