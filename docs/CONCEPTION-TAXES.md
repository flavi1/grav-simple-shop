# Conception — Taxes (15/10/2026, révisée à 14 h 35)

> Conception seulement : rien n'est développé. Les décisions à prendre sont en §7.
> Objectif : un modèle **classique** (celui de WooCommerce et de PrestaShop), simple pour ce site, mais capable de servir
> d'autres pays plus tard. Valeurs par défaut : les taux de TVA français, et le taux réduit de 5,5 % appliqué aux livres.

## 1. Comment font WooCommerce et PrestaShop

| | WooCommerce | PrestaShop |
|---|---|---|
| Ce qu'on attache au produit | Une **classe de taxe** (Standard, Taux réduit, Taux zéro, + classes libres) | Un **groupe de règles de taxe** (ex. « FR Taux réduit 5,5 % ») |
| Où sont les taux | Une **table de taux** par classe : pays, région, code postal, ville, taux %, nom, **priorité**, **composée**, **s'applique à la livraison** | Les règles du groupe : pays, région, codes postaux, taxe, comportement (« cette taxe seulement », « combiner », « l'une après l'autre ») |
| Plusieurs taxes sur un produit | Plusieurs lignes de taux de priorités différentes (ex. Canada : TPS + TVQ), éventuellement composées | Comportements « combiner » ou « l'une après l'autre » |
| Taxe de la livraison | Par défaut **selon les articles du panier** ; panier mixte : **taux le plus élevé** ; ou une classe fixe choisie. Chaque taux a sa case « livraison » | Un groupe de règles par transporteur ; une option de taxe moyenne des produits existerait aussi (non vérifiée dans la documentation actuelle) |
| Adresse qui décide | Adresse de livraison, de facturation ou de la boutique (réglage) | Adresse de livraison ou de facturation (réglage) |
| Prix saisis | HT ou TTC (réglage global) | HT dans la fiche, TTC calculé |

Sources : [WP Desk — tutoriel des taxes WooCommerce](https://www.wpdesk.net/blog/woocommerce-taxes-complete-tutorial/),
[documentation PrestaShop 8 — transporteurs](https://docs.prestashop-project.org/v.8-documentation/french-1/guide-utilisateur/optimiser-boutique/gerer-livraisons/gerer-transporteurs).

**Choix** : reprendre le modèle WooCommerce (classes + table de taux), plus simple à expliquer que les groupes de règles de
PrestaShop, avec une seule différence : **la livraison d'un panier mixte est taxée au prorata** (règle française et européenne)
plutôt qu'au taux le plus élevé, ce dernier restant proposé en option.

## 2. Ce que dit la loi française (rappel du 15/10/2026, matin)

- La livraison facturée fait partie du prix ; elle suit le taux des produits livrés
  ([BOFiP BOI-TVA-LIQ-10, § 70](https://bofip.impots.gouv.fr/bofip/1380-PGP.html/identifiant=BOI-TVA-LIQ-10-20140919)).
- Panier à taux différents : pratique courante = répartir le port **au prorata du HT** des articles de chaque taux
  ([L-Expert-Comptable](https://www.l-expert-comptable.com/a/tva-frais-de-port)). Le taux le plus élevé sur tout le port fait
  payer trop de TVA au client : admis en pratique par certains, mais pas la règle.
- Exonérations : formation professionnelle (« TVA non applicable, art. 261-4-4°-a du CGI »), franchise en base (art. 293 B).
- Facture électronique (EN 16931) : **une seule TVA par ligne** ; l'exonération demande une catégorie (`E`) et une mention.

Je ne suis pas juriste : à faire valider par un expert-comptable avant de vendre autre chose que des livres.

## 3. Le modèle

### 3.1 Classes de taxe

Une classe = « une sorte de produit au regard de la taxe ». C'est elle qu'on attache aux types de produits, jamais un taux.

| Code | Libellé | Usage |
|---|---|---|
| `standard` | Taux normal | Défaut de tout produit sans classe |
| `intermediaire` | Taux intermédiaire | |
| `reduit` | Taux réduit | **Livres** |
| `particulier` | Taux particulier | Presse, médicaments remboursables… |
| `zero` | Taux zéro | |
| `exonere` | Exonéré | Formation professionnelle exonérée… |

### 3.2 Table des taux (une liste par classe)

| Champ | Exemple | Rôle |
|---|---|---|
| Pays | `FR` (vide = tous) | Code ISO à deux lettres |
| Région / codes postaux | vide ; `20*` (Corse), `971*`… | Facultatif : affiner (DOM, Corse, États américains…) |
| Taux % | 5,5 | Stocké en points de base (550) |
| Nom | TVA 5,5 % | Affiché au client et sur la facture |
| Priorité | 1 | Plusieurs taxes sur un même produit = plusieurs priorités |
| Composée | non | Calculée sur le prix déjà taxé (ex. ancienne TVQ du Québec) |
| S'applique à la livraison | oui | La case de votre demande : ce taux peut-il taxer les frais de port |
| Catégorie (facture) | `S` normal ou réduit / `Z` zéro / `E` exonéré | Catégorie de la facture électronique |
| Mention | TVA non applicable, art. 261-4-4°-a du CGI | Obligatoire pour `E`, imprimée sur la facture |

Résolution, comme WooCommerce : pour la classe du produit et l'adresse retenue, on garde à chaque priorité la ligne la plus
précise qui correspond (code postal > région > pays > tous) ; les taux trouvés s'additionnent, les « composés » s'appliquent ensuite.

### 3.3 Réglages généraux (Boutique > Taxes)

| Réglage | Valeurs | Défaut pour ce site |
|---|---|---|
| Activer les taxes | oui / non | oui |
| Prix saisis | HT / TTC | TTC (décision du 14/10) |
| Adresse qui décide du taux | boutique / livraison / facturation | **boutique** (vente en France uniquement) |
| Taxe de la livraison | **au prorata des articles** / taux le plus élevé des articles / une classe fixe | au prorata |
| Arrondi | par ligne / par total | par ligne (déjà le cas) |
| Affichage | prix TTC + suffixe « TTC » | déjà le cas |
| Franchise en base (293 B) | oui / non + mention | non |

### 3.4 Types de produits

Tableau dans le même écran : type de produit (découvert dans les blueprints) → classe. Le `shop.tax` du blueprint devient la
classe par défaut ; le choix fait dans l'administration l'emporte. Une fiche peut surcharger (champ `tax_class`), comme `price_entered_as`.

### 3.5 Valeurs livrées par défaut (France métropolitaine)

| Classe | Pays | Taux | Nom | Livraison | Catégorie |
|---|---|---|---|---|---|
| standard | FR | 20 | TVA 20 % | oui | S |
| intermediaire | FR | 10 | TVA 10 % | oui | S |
| reduit | FR | 5,5 | TVA 5,5 % | oui | S |
| particulier | FR | 2,1 | TVA 2,1 % | oui | S |
| zero | FR | 0 | TVA 0 % | oui | Z |
| exonere | FR | 0 | Exonéré | oui | E + mention à saisir |

Type `book` → classe `reduit`. Corse et départements d'outre-mer : pas de ligne (le site ne livre que la France métropolitaine hors Corse) ;
s'ajoutent plus tard par code postal sans rien changer au code.

## 4. Calculs

- **Article** : taux de sa classe pour l'adresse retenue, en centimes, sur le prix saisi (HT ou TTC), comme aujourd'hui.
- **Livraison** (prix saisis HT dans les grilles) :
  - *au prorata* : le HT de livraison est réparti entre les classes des articles **expédiés**, au prorata de leur HT ; chaque part reçoit
    les taux de sa classe dont la case « livraison » est cochée. Centimes répartis exactement (le reste d'arrondi va à la plus grosse part) ;
  - *taux le plus élevé* : toute la livraison prend la classe de l'article expédié au taux le plus haut ;
  - *classe fixe* : toute la livraison prend la classe choisie.
- Contraintes de livraison (loi Darcos, offre dès 50 €) : inchangées, sur le TTC, après ce calcul. Un TTC imposé (3,00 €) est
  réparti de la même façon pour retrouver le HT de chaque part.
- Exemple : livres 40 € HT (5,5 %) + objet 60 € HT (20 %), port 10 € HT → 4 € à 5,5 % + 6 € à 20 % = 11,42 € TTC.

## 5. Effets sur le reste

| Partie | Changement |
|---|---|
| Panier et tunnel | Aucun visible pour ce site (tout à 5,5 %) ; récapitulatif « dont TVA » par taux possible. |
| Commande | Chaque ligne garde déjà son taux ; la livraison est enregistrée **par part de taux** (nouvelle table, migration additive). |
| Facture | Une ligne de livraison par taux ; plusieurs taxes sur une ligne = hors norme EN 16931 : dans ce cas (hors France), PDF simple sans XML Factur-X. |
| Simulation de livraison (admin) | Ajouter la composition du panier par classe. |
| Configuration actuelle | `taxes.rates` (TVA_5_5, TVA_20) converti une fois en classes ; les anciennes commandes gardent leurs codes. |
| Tests | Panier mono-taux, mixte (exemple §4) pour les trois modes de livraison, exonération, taxe composée, facture validée au XSD. |

## 6. Ce qui ne change pas pour ce site

Livres à 5,5 %, livraison à 5,5 % (prorata d'un panier 100 % livres), mêmes prix TTC, mêmes factures. Le travail prépare les
autres produits (20 %, formations exonérées) et d'autres pays.

## 7. Décisions à prendre

1. **Modèle** : classes de taxe + table de taux à la WooCommerce (§3), avec les valeurs françaises par défaut (§3.5) ?
2. **Livraison d'un panier mixte** : au prorata par défaut, « taux le plus élevé » et « classe fixe » en option (§4) ?
3. **Adresse qui décide du taux** : boutique par défaut (vente en France), livraison et facturation proposées pour plus tard ?
4. **Exonérations et franchise en base** : dans ce lot (catégorie `E` + mention) ?
5. **Hors périmètre de ce lot** (à confirmer) : guichet unique européen (OSS), taxes américaines automatiques (services externes
   type TaxJar/Avalara), éco-participation. Le modèle les permet, sans les programmer.
6. **Priorité** : ce lot maintenant, ou après l'étape 6 (panier avec options) ?

## 8. Décisions du 15/10/2026 (14 h 46)

1. Modèle validé (classes de taxe + table de taux, valeurs françaises par défaut, livres en taux réduit).
2. Comme WooCommerce : taxe de la livraison « selon les articles du panier » par défaut (panier mixte : **taux le plus élevé**),
   ou une classe fixe ; le **prorata** (pratique française recommandée, §2) est proposé en plus. Pour ce site (100 % livres), même résultat.
3. Comme WooCommerce : taux décidé par l'**adresse de livraison** du client (défaut), ou de facturation, ou de la boutique ;
   avant la saisie de l'adresse (fiche produit, panier), adresse de la boutique.
4. Exonérations et franchise en base : incluses (catégorie `E` + mention).
5. Inclus, et rien de plus : taux par pays (guichet unique européen OSS : lignes par pays + import/export CSV des taux) et point
   d'extension pour un service de taxes externe (événement `onSimpleShopTaxRates`, ex. taxes américaines) sans en programmer aucun.
   L'éco-participation reste un supplément de prix du type de produit (§3 de la première version), pas une taxe.
6. Ordre : étape 6 de `CONCEPTION-PRODUITS-PRIX.md` (lignes de panier avec options) d'abord, car elle change la clé des lignes de
   panier sur laquelle les taxes s'appuient ; puis ce lot.

## 9. Avancement (15/10/2026)

Réalisé : modèle complet (classes, table des taux, adresse, livraison `items` / `items_prorata` / classe fixe, taxes multiples et composées,
exonération, franchise en base, taxes désactivables), réglages dans Plugins > Simple Shop > Taxes, vue d'ensemble et import/export CSV dans
Boutique > Taxes, événement `onSimpleShopTaxRates`, factures (une ligne de livraison par taux, motif d'exonération). Tests : `tests/taxes.php`.
Changement visible pour un produit sans type (« produit générique ») : il passe de la taxe du site (5,5 %) à la classe par défaut (taux normal, 20 %).

## 10. Simplification du 15/10/2026 (après-midi) : plus de « classes »

Demande : « Différencier Classes de taxe et Taux me semble assez lourd… à moins qu'il y ait un usage légitime (par exemple à
l'étranger), on se contentera des Taux. » L'usage légitime existe (un même produit taxé différemment selon le pays de livraison :
OSS, DOM), mais il ne demande pas une notion séparée : **le nom du taux joue le rôle de la classe**.

- Une seule table `taxes.rates` : `name` (« Taux réduit »), `rate`, `country`, `region`, `postcodes`, `label` (libellé de facture,
  ex. « TVA 5,5 % »), `priority`, `compound`, `shipping`, `category`, `mention`.
- Types de produits (`shop.tax`, `taxes.types` `{type, rate}`), fiches (`tax_rate`), taux par défaut (`taxes.default_rate`) et
  livraison fixe (`taxes.shipping_tax`) désignent un taux **par son nom** ; plusieurs lignes du même nom = variantes géographiques.
- Clé interne (nom sans accents, `taux-reduit`) enregistrée comme `tax_code` des commandes ; anciens codes (`reduit`, `TVA_5_5`),
  `taxes.classes` + lignes `{class, name}` et champ `tax_class` toujours compris.
- CSV : `nom;taux;pays;region;codes_postaux;libelle;priorite;composee;livraison;categorie;mention`.

## 11. Types de taxe contenant leurs taux (15/10/2026, 16 h)

Retour du propriétaire sur le §10 : puisqu'un même produit doit pouvoir être taxé différemment selon le pays, la notion de classe est
légitime — mais présentée comme un **type de taxe** (« je préfère type de taxe ») qui **contient** un ou plusieurs taux, plutôt que deux
listes séparées. Validé.

- Configuration : `taxes.tax_types: [{name, rates: [{rate, country, region, postcodes, label, priority, compound, shipping, category, mention}]}]`,
  `taxes.default_type`, `taxes.product_types: [{type, tax_type}]`, champ de fiche `tax_type`. Les formats précédents (§8 à §10) restent lus.
- Interface (Admin2 > Boutique > Taxes) : un `<details>` **ouvert** par type (nom, boutons « Ajouter un taux », « Supprimer ce type »),
  et dans chacun un `<details>` **fermé** par taux (résumé : pays · taux · libellé). Enregistrement : `PUT /simple-shop/taxes/types`.
  Le formulaire du plugin garde la même liste imbriquée (repli `collapsed` sur les taux), utile surtout pour ne pas perdre les données.
- CSV inchangé dans l'esprit : une ligne par taux, première colonne = type (`type;taux;pays;region;codes_postaux;libelle;priorite;composee;livraison;categorie;mention`).

