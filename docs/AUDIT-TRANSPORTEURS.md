# Audit — gestion des transporteurs : simple-shop face aux modules Colissimo existants (14/10/2026)

> Objet : savoir si notre gestion des tarifs (grilles par tranches) est trop lourde comparée à ce que font
> les autres boutiques, et quoi simplifier. Sources en fin de document.

## 1. Ce que l'API Colissimo permet (et ne permet pas)

- **Accès** : les modules et services web Colissimo demandent un contrat professionnel, **Facilité ou Privilège**
  (« A Colissimo contract (Facilité or Privilège) is required »). Facilité : sans engagement ni volume minimum, adapté aux
  petits volumes. Identifiants : clé d'application (recommandée) ou identifiant + mot de passe de la Colissimo Box.
  Certains services (web service « local » d'étiquettes, Flash) passent par le commercial ou une annexe du contrat Privilège.
- **Services web publiés** : points de retrait (celui que nous utilisons), affranchissement / étiquettes, documents
  (douane, preuves de livraison), suivi, enlèvement à la demande.
- **Pas d'API de tarifs.** Aucun service ne renvoie le prix d'un envoi ni la grille du contrat : les tarifs sont des
  grilles annuelles (PDF, Colissimo Box). Même le module officiel WooCommerce **embarque** la grille de l'année dans
  ses mises à jour et propose un bouton « importer / réinitialiser aux tarifs de l'année ». Les plateformes tierces
  (ShippyPro…) disent la même chose : pas de tarif temps réel, simulation à partir de la grille du contrat.

## 2. Comparaison

| Critère | Module Colissimo WooCommerce (officiel, 10 000+ installations, note 3,7/5) | Module Colissimo PrestaShop (officiel) | simple-shop (nous) |
|---|---|---|---|
| Où vivent les tarifs | Grille par **zone × méthode**, tranches de poids (ou de prix, de catégorie) | Transporteurs natifs PrestaShop (tranches poids/prix par zone) + « pondération » (surcoût) | Grille par **mode**, tranches poids / panier / épaisseur, zone par ligne |
| Grille de départ | Tarifs de l'année pré-remplis, bouton d'import par méthode, export / import | Tranches à saisir dans PrestaShop | Grille Facilité livrée par le plugin, « Rétablir les valeurs du plugin » |
| Saisie HT | Oui | Selon PrestaShop | Oui (TVA ajoutée automatiquement) |
| Poids d'emballage | Oui (un réglage) | — | Oui (poids **et** épaisseur) |
| Livraison offerte dès X € | Oui, par méthode | Natif PrestaShop | Oui, par mode |
| Surcoût | Surcoût global (fixe) | Pondération | Marge fixe **et** % par mode |
| Plancher légal (loi Darcos) | Non | Non | **Oui**, automatique |
| Simulateur de prix | Non | Non | **Oui** |
| Nombre de réglages | ~9 par zone × méthode, plus de nombreux onglets (compte, étiquettes, douane…) | ~45 options au total | ~10 par mode, plus 3 dans la page du plugin |
| Étiquettes, suivi automatique | Oui | Oui | Non (numéro saisi à la main) |
| Plainte récurrente | « il faut rentrer toutes les tranches à la main à chaque changement de tarifs » | — | (pas encore en service) |

**Verdict** : notre modèle est **le même que celui du module officiel** (grille de tranches pré-remplie, saisie HT,
emballage, « offert dès », surcoût). Nous n'en faisons pas trop sur le fond ; nous ajoutons même ce qui manque
ailleurs (Darcos, simulateur, marge %). Ce qui est lourd, c'est **l'écran** : la grille de 33 lignes à 7 colonnes est
affichée et éditable d'emblée, alors que, comme chez WooCommerce, presque personne ne devrait y toucher.

## 3. Ce que font les petits marchands

- La plupart n'éditent pas les tranches : ils prennent la grille fournie, ou un **forfait** (« 5 € », « offert dès 50 € »).
- Pour les **livres en France**, la loi Darcos aplatit tout : la grande distribution (Amazon, Fnac, Cultura…) facture
  depuis fin 2023 **3 € sous 35 € et 0,01 € au-delà**, quel que soit le poids. Un petit éditeur peut faire pareil et
  absorber la différence, ou facturer un forfait proche du coût réel (lettre suivie / Colissimo ≈ 8 € pour un livre).
- Le prix au gramme près n'a d'intérêt que si les colis varient beaucoup (coffrets, commandes de 5+ livres).

## 4. Recommandation : simplifier l'écran, garder le moteur

Le moteur (grilles, marges, plancher, simulateur) reste ; seule la présentation change :

1. **Choix du mode de calcul par mode de livraison**, en tête du formulaire :
   - « Forfait » : **un seul prix** (et éventuellement « offert dès X € ») — le cas courant ;
   - « Tarif Colissimo » : grille du plugin, mise à jour avec le plugin chaque année — lecture seule par défaut ;
   - « Grille personnalisée » : l'écran actuel.
2. **Grille repliée** : pour les modes plugin, un résumé (« Grille Facilité 2025 : 33 tranches, de 6,72 € à 36,39 € HT »)
   et un bouton « Personnaliser la grille » ; colonnes rares (panier min / max, épaisseur) masquées sauf besoin.
3. **Mise à jour annuelle** sans ressaisie : nouvelle grille livrée dans le plugin (+ import CSV d'une grille copiée depuis
   la Colissimo Box, en option) — c'est précisément la plainte des utilisateurs du module WooCommerce.
4. ~~Préréglage « Livres France »~~ — remplacé le 14/10/2026, à la demande du propriétaire, par des **contraintes globales**
   génériques (la loi Darcos en est une ; simple-shop ne connaît plus les livres).

Coût estimé : modéré (écran Admin2 + un type de calcul « forfait » dans le moteur ; aucune donnée à migrer).

Note : un forfait est déjà possible aujourd'hui (mode manuel avec une seule ligne de grille, sans limite de poids) ;
la recommandation rend ce cas visible et simple au lieu de le cacher dans la grille.

## Sources

- Colissimo, modules WooCommerce (contrat Facilité ou Privilège requis) : https://www.colissimo.entreprise.laposte.fr/en/tools-and-solutions/e-commerce-modules-and-solutions/woocommerce-solutions
- Colissimo, contrats : https://www.colissimo.entreprise.laposte.fr/en/offer-and-services/our-contracts et https://www.colissimo.entreprise.laposte.fr/en/facilite-contract
- Colissimo, outils et services web : https://www.colissimo.entreprise.laposte.fr/en/tools-and-services
- Guide du module WooCommerce (tarifs pré-remplis, saisie HT, poids d'emballage, offert dès) : https://www.colissimo.entreprise.laposte.fr/media/1603/download
- Fiche du module WooCommerce (installations, note, avis) : https://wordpress.org/plugins/colissimo-shipping-methods-for-woocommerce/
- Guide du module PrestaShop : https://www.colissimo.entreprise.laposte.fr/media/142/download
- Grille Privilège 2025 (aucune API de tarifs mentionnée) : https://www.colissimo.entreprise.laposte.fr/media/1193/download
- ShippyPro, pas d'API de tarifs temps réel : https://www.shippypro.com/blog/fr/comment-connecter-colissimo-à-woocommerce-le-guide-complet-2026
