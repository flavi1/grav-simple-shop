Du point de vue du développement, l'intégration directe des transporteurs vous forcerait à jongler avec l'API SOAP de [Colissimo](https://www.laposte.fr/colissimo), l'API REST de Mondial Relay et les spécificités de La Poste.
Les plateformes d'envoi (souvent appelées agrégateurs ou Shipping APIs) résolvent exactement ce problème de fragmentation.

## 🔌 Une API unique et uniformisée
Des plateformes comme [Sendcloud](https://www.sendcloud.com/fr/), Boxtal, ou [Shipup](https://www.shipup.co/) proposent des APIs REST modernes, extrêmement bien documentées (en OpenAPI/Swagger) qui uniformisent (wrappent) tous les transporteurs. [1] 
Avec une seule et unique structure de données JSON, vous pilotez tous vos transporteurs :

* Pour récupérer les tarifs : GET /rates vous renvoie les prix de Colissimo et Mondial Relay en même temps.
* Pour créer une étiquette : POST /shipments génère le bordereau, que le transporteur final soit La Poste ou Shop2Shop. Le format du payload reste identique.

------------------------------
## 🗺️ Le cas critique de la sélection des Points Relais
L'intégration d'un point relais nécessite d'afficher une carte ou une liste sur votre site PHP au moment du tunnel d'achat (checkout). Les agrégateurs d'API gèrent cela de deux manières :
## Option A : Via des widgets Front-end clés en main (Recommandé)
Les plateformes (Sendcloud et Boxtal notamment) fournissent un SDK JavaScript ou un Widget prêt à l'emploi.

   1. Vous intégrez une ligne de code JS sur votre page de paiement PHP.
   2. Le script appelle directement l'API de l'agrégateur pour afficher une carte dynamique (Google Maps/OpenStreetMap) avec les points de retrait disponibles selon l'adresse du client.
   3. Une fois le point choisi par l'utilisateur, le widget renvoie l'identifiant du relais (ID Mondial Relay par exemple) à votre code PHP via un callback JS pour que vous l'enregistriez en base de données.

## Option B : Via des endpoints d'API (Sur-mesure)
Si vous préférez coder votre propre interface graphique :

   1. Votre code PHP fait un appel vers l'API de l'agrégateur (ex : GET /v1/parcel-shop-locations?postal_code=75011&carrier=mondial-relay).
   2. L'API vous renvoie un JSON propre contenant la liste des points, les coordonnées GPS et les horaires d'ouverture.
   3. Vous construisez vous-même votre affichage en PHP/JS. [2, 3] 

------------------------------
## 🛠️ Quelle plateforme choisir pour une intégration PHP ?
Voici les trois solutions les plus adaptées pour votre stack technique :
## 1. Sendcloud (La plus orientée développeurs)

* Documentation : Exemplaire, moderne (REST / JSON).
* Le plus "Dev" : Ils disposent d'un SDK PHP officiel (via Composer) et d'un widget de sélection de points de collecte (Service Point Picker) très simple à brancher en JavaScript.
* Webhooks : Système de webhooks robuste pour mettre à jour automatiquement le statut de la commande dans votre base PHP (ex: colis livré, colis prêt...).

## 2. Boxtal (La solution historique française)

* Documentation : API REST bien conçue et en français.
* Le plus "Métier" : Très performant sur le marché français (très bons tarifs négociés sur Colissimo/Mondial Relay).
* Intégration : Moins de SDKs officiels récents que Sendcloud, mais l'API HTTP se mappe très facilement avec un client HTTP PHP standard (comme Guzzle).

## 3. Shippo ou Packlink PRO

* Alternatives viables, mais Shippo est historiquement plus centré sur le marché américain/international (bien que Colissimo y soit disponible), et Packlink a une documentation API parfois moins moderne que Sendcloud.

------------------------------
## 📐 Exemple de workflow technique (PHP / JS)
Pour implémenter cela sur votre site d'édition :

   1. Checkout (Front-end JS) : L'utilisateur saisit son adresse. Si le choix "Mondial Relay" est coché, le Widget JS de l'agrégateur s'ouvre et affiche les relais. L'utilisateur clique sur un relais. Le script JS injecte l'ID du relais dans un champ caché de votre formulaire de paiement.
   2. Validation (Back-end PHP) : Le formulaire est soumis. Votre script PHP récupère l'adresse du client et l'ID du point relais choisi, puis valide la commande en BDD.
   3. Fulfillment (Back-end PHP) : Au moment de préparer le colis, votre script PHP fait une requête POST vers l'API de l'agrégateur avec les dimensions du livre. L'API renvoie l'URL du fichier PDF de l'étiquette et le numéro de suivi uniformisé.

Pour aller plus loin, utilisez-vous un framework PHP spécifique ([Symfony](https://fr.wikipedia.org/wiki/Symfony), Laravel) ou un CMS (WordPress/WooCommerce) pour votre site d'édition ? De nombreux agrégateurs proposent des modules natifs qui évitent d'écrire la moindre ligne de code d'API. [4] 

[1] [https://www.skiils.com](https://www.skiils.com/blog/api-first-concevoir-des-applications-plus-robustes-grace-au-contrat-api-openapi-asyncapi-gouvernance-et-qualite)
[2] [https://esensconsulting.medium.com](https://esensconsulting.medium.com/un-serveur-de-mock-dans-une-architecture-microservices-efa6727f8427)
[3] [https://axons.fr](https://axons.fr/blog/rest-jusqu-au-bout-a-quoi-sert-vraiment-hateoas-sur-une-api-metier)
[4] [https://www.prestacrea.com](https://www.prestacrea.com/blog/15-outils-de-developpement-des-principaux-navigateurs-web)



-------------------------------

Moi => On veut du NoJS. La selection sera donc gérée par PHP. Quelle plateforme choisir? => tableau comparatif coût pour client-vendeur (ce sera l'argument massu) + API la plus pratique (si ma demande est entièrement couverte c'est déjà bien), permettant du NO JS (indispensable)


-------------------------------

Faire du NoJS (100% PHP côté serveur) pour la sélection de points relais est un excellent choix architectural pour la performance, l'accessibilité et la simplicité de maintenance.
Cependant, cela élimine d'office les plateformes qui reposent sur des "widgets" ou des "maps" JavaScript pré-construites. Vous devez obligatoirement requêter la liste des points relais en PHP via l'API, puis générer votre propre affichage HTML (une liste de boutons radios <input type="radio"> ou un <select> natif avec rechargement de page/soumission de formulaire).
Voici l'analyse comparative des plateformes sous cet angle strict, avec le coût comme argument de poids.
------------------------------
## 📊 Tableau comparatif : Boxtal vs Sendcloud vs Shipup (Axe NoJS & Coûts)

| Critère stratégique | Boxtal (Le choix "Éditeur") | Sendcloud (Le choix "Dev") | Intégration Directe (Mondial Relay / Colissimo) |
|---|---|---|---|
| Coût d'abonnement vendeur | 0 € / mois (Payez uniquement à l'étiquette) | Dès 28 € à 89 € / mois pour débloquer l'accès complet à l'API | 0 € / mois |
| Coût des étiquettes (Client/Vendeur) | Tarifs de grossiste très agressifs dès le 1er colis (Ex: Mondial Relay ~4,60€) | Tarifs négociés corrects + 0,10 € de frais fixes par étiquette imprimée | Tarifs publics chers (sauf si vous négociez vous-même avec de gros volumes) |
| Faisabilité NoJS (PHP pur) | Excellente. Endpoints API clairs pour l'extraction brute de points relais. | Possible, mais l'API pousse fortement vers leur Widget JS. | Excellente. Mondial Relay fournit un webservice natif pour lister les points. |
| Praticité de l'API | API REST JSON v3 très stable et bien documentée. | API REST extrêmement moderne, SDK PHP officiel (Composer). | Catastrophique (Mondial Relay = vieux protocole SOAP/XML, Colissimo = rigide). |

------------------------------
## 🏆 L'argument massue : Pourquoi BOXTAL est le vainqueur évident pour vous
Pour une petite maison d'édition, [Boxtal](https://www.boxtal.com/fr/fr/tarifs-expedition) est la plateforme idéale. [1, 2] 

   1. Le modèle économique est parfait : [Sendcloud](https://www.sendcloud.com/fr/tarifs/) facture un abonnement mensuel (minimum 28€/mois pour utiliser l'API) plus une taxe sur chaque étiquette. Boxtal est gratuit (sans abonnement). Si vous vendez 10 livres un mois, vous ne payez que les 10 étiquettes au tarif négocié. Pas de frais fixes d'infrastructure. [3, 4, 5, 6] 
   2. L'API gère le NoJS nativement : Contrairement à d'autres qui masquent l'accès aux points relais derrière un script JS propriétaire, l'API de Boxtal met à disposition un point d'accès (endpoint) HTTP standard pour récupérer les données brutes des points de retrait. [7] 

------------------------------
## 🛠️ Comment gérer la sélection Point Relais en PHP pur (Workflow NoJS)
Puisque vous n'utilisez pas de JavaScript pour afficher une carte, voici la logique exacte à implémenter dans votre tunnel d'achat PHP :

[Étape 1 : Saisie Adresse] ──> Client tape son Code Postal (ex: 75011) et valide (Formulaire HTML)
                                      │
                                      ▼
[Étape 2 : Traitement PHP] ──> Votre script PHP fait un appel cURL vers l'API de l'agrégateur :
                               GET /v3/points-relais?code_postal=75011&transporteur=mondial-relay
                                      │
                                      ▼
[Étape 3 : Affichage HTML] ──> PHP reçoit le JSON, boucle dessus et génère un formulaire 100% HTML :
                               ○ [ ] Relais A - 12 rue des Livres (Ouvert 9h-19h)
                               ○ [ ] Relais B - 45 av de l'Éditeur
                               [ Bouton : Valider mon choix et Payer ]

## Ce que vous recevez de l'API en PHP (Exemple de charge utile JSON de l'API) :
L'API vous renvoie un tableau propre que vous pouvez trier et afficher directement en PHP :

[
  {
    "id": "MR-12345",
    "name": "Superette du Centre",
    "address": "12 Rue de la Paix",
    "zipcode": "75011",
    "city": "Paris",
    "opening_hours": { "monday": "08:00-19:00", "tuesday": "08:00-19:00" }
  }
]

Dans votre code PHP, il vous suffit de stocker l'ID "MR-12345" dans votre table commandes. Au moment de générer l'étiquette via l'API, vous passerez cet identifiant pour indiquer le point de livraison.
## 🎯 Conclusion
Fuyez l'intégration directe des transporteurs (trop complexe à maintenir en PHP pur à cause de formats d'API vieillissants). Tournez-vous vers l'API Boxtal : elle vous offre l'uniformisation des requêtes, le support complet du NoJS (extraction brute des données de points relais par code postal) et un coût de fonctionnement nul (0 € de frais fixes). [4, 7] 
Souhaitez-vous un exemple concret de code PHP (requête de test avec cURL ou Guzzle) pour visualiser comment récupérer cette liste de points relais depuis l'API ?

[1] [https://www.secursus.com](https://www.secursus.com/fr-fr/ressource/article/Boxtal/)
[2] [https://www.lafabriquedunet.fr](https://www.lafabriquedunet.fr/logiciel/boxtal)
[3] [https://www.sendcloud.com](https://www.sendcloud.com/pricing/)
[4] [https://shipadvisor.fr](https://shipadvisor.fr/transporteurs/boxtal/)
[5] [https://www.boxtal.com](https://www.boxtal.com/fr/fr/tarifs-expedition)
[6] [https://supply-chain.net](https://supply-chain.net/boxtal-gerer-ses-expeditions-e-commerce-sans-se-ruiner/)
[7] [https://dawap.fr](https://dawap.fr/integration-api/logistique-shipping/integrateur-boxtal)


-------------------------------

coût des etiquettes BOXTAL?

-------------------------------

Chez Boxtal, le modèle économique repose entièrement sur le volume : l'inscription est gratuite, il n'y a aucun abonnement mensuel, et vous ne payez que le coût réel des étiquettes que vous générez via votre API [Boxtal](https://www.boxtal.com/fr/fr/tarifs-expedition). [1, 2, 3] 
En tant que professionnel (petite maison d'édition), vous bénéficiez immédiatement de tarifs négociés de grossiste dès le premier colis, sans engagement de volume. [4, 5] 
------------------------------
## 📦 Grille des tarifs indicatifs Boxtal pour la France (Professionnels)
Les prix des étiquettes varient selon le poids du colis. Les tarifs négociés par Boxtal s'articulent généralement ainsi : [6] 
## 1. Mondial Relay (Livraison en Point Relais) – L'option économique [7] 

* Moins de 500 g (un roman poche/moyen) : ~3,00 € à 3,50 € HT
* Jusqu'à 1 kg (un gros livre ou 2 romans) : ~4,20 € à 4,60 € HT
* Jusqu'à 2 kg (beaux livres, BDs ou commandes groupées) : ~5,50 € à 6,00 € HT [6, 7, 8, 9] 

## 2. Colissimo (Livraison à domicile sans signature) – L'option confort

* Moins de 250 g (un livre très léger) : ~5,10 € HT
* Jusqu'à 500 g : ~6,20 € HT
* Jusqu'à 1 kg : ~7,50 € HT [9, 10] 

💡 Précision importante : Contrairement à d'autres plateformes (comme Sendcloud) qui ajoutent des frais fixes de traitement (ex: +0,10 € par étiquette imprimée), les prix affichés par Boxtal sont "tout compris". Il n'y a aucune ligne de frais cachés lors de la génération de l'étiquette par l'API. [1] 

------------------------------
## ⚠️ Les 3 pièges des "frais annexes" à anticiper en Dev/Logistique
Le coût d'une étiquette Boxtal peut être majoré par les transporteurs finaux si le colis ne respecte pas les règles d'automatisation des centres de tri. En codant votre interface, vous devez sensibiliser l'utilisateur ou automatiser vos calculs de colisage pour éviter ces frais : [11] 

   1. Le dépassement de poids (Le plus fréquent) : Si vous déclarez un livre à 400g sur l'API mais que le colis réel fait 600g à la pesée sur le tapis du transporteur, Boxtal appliquera une régularisation de poids sur votre facture mensuelle (le prix de la tranche supérieure + parfois de légers frais de dossier du transporteur). Solution dev : configurez bien le poids de chaque livre dans votre base de données et calculez automatiquement le poids total de la commande + 50g d'emballage. [11] 
   2. Le supplément "Non Mécanisable" (~3 € à 6 € d'amende par colis) : Si vous envoyez un livre dans un emballage de forme cylindrique (tube), si l'étiquette est collée sur une arête ou si une ficelle/un scotch dépasse, le scanner automatique du transporteur rejette le colis. Il doit être traité à la main, ce qui déclenche une taxe de non-mécanisabilité. [11] 
   3. L'assurance optionnelle (Secursus / Boxtal AXA) : Par défaut, les étiquettes incluent une indemnisation minimale standard (ex: ~23€/kg). Si vous expédiez une commande de livres d'une valeur de 150 €, vous pouvez demander via l'API l'ajout d'une assurance ad valorem (généralement facturée entre 1% et 3% de la valeur déclarée du colis). [12] 

## 🎯 En résumé pour votre business plan
Pour un roman classique envoyé à un particulier, l'étiquette vous coûtera environ 3,50 € HT en point relais (Mondial Relay) et environ 6,20 € HT à domicile (Colissimo), sans aucun autre frais de structure numérique. Vous pouvez répercuter exactement ces montants à vos clients de manière transparente sur votre boutique PHP.
Si vous souhaitez passer à l'étape suivante, je peux vous fournir le modèle d'appel JSON exact à envoyer en PHP pour créer une étiquette et récupérer son PDF d'impression depuis l'API Boxtal. Qu'en pensez-vous ?

[1] [https://www.boxtal.com](https://www.boxtal.com/fr/fr/tarifs-expedition)
[2] [https://help.boxtal.com](https://help.boxtal.com/fr/fr/article/transport-price-list)
[3] [https://www.lafabriquedunet.fr](https://www.lafabriquedunet.fr/logiciels/alternatives/alternative-boxtal)
[4] [https://www.boxtal.com](https://www.boxtal.com/fr/fr/preuves/transporteurs)
[5] [https://addons.prestashop.com](https://addons.prestashop.com/fr/solutions-livraison-retrait/1755-boxtal-connect-solution-d-expedition-cle-en-main.html)
[6] [https://www.2htransports.com](https://www.2htransports.com/colissimo-ou-mondial-relay.html)
[7] [https://resource.boxtal.com](https://resource.boxtal.com/documents/tarifs/tarifs-boxtal.pdf)
[8] [https://www.boxtal.com](https://www.boxtal.com/fr/fr/preuves/transporteurs/mondial-relay)
[9] [https://www.secursus.com](https://www.secursus.com/fr-fr/ressource/article/Boxtal/)
[10] [https://www.boxtal.com](https://www.boxtal.com/fr/fr/preuves/transporteurs/colissimo)
[11] [https://help.boxtal.com](https://help.boxtal.com/fr/en/article/what-additional-invoices-are)
[12] [https://help.boxtal.com](https://help.boxtal.com/fr/en/article/copr-compensation-conditions)

