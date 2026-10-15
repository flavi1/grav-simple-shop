# Sécurité

## Signaler une vulnérabilité

Merci de **ne pas ouvrir de ticket public**. Utilisez le signalement privé de GitHub : onglet *Security* → *Report a
vulnerability* de ce dépôt. Réponse sous quelques jours ; un correctif est publié sur `main` puis déployé.

## Principes
- Aucun secret dans ce dépôt (clés Stripe, identifiants Colissimo, SMTP) : ils vivent dans la configuration de chaque site
  (`user/config/plugins/<plugin>.yaml`), jamais versionnée.
- Le plugin est **désactivé par défaut** ; chaque site l'active explicitement.
- Formulaires publics protégés par nonce (CSRF) ; API d'administration soumise aux permissions `api.simple-shop.read` / `write` ;
  requêtes SQL préparées ; données clients (profils, commandes, factures) dans `user/data`, jamais servies par le serveur web ;
  codes OTP aléatoires (`random_int`), 10 min, 5 essais, plafonds d'envoi par e-mail et par IP ; identifiant de session renouvelé
  à l'identification et à la déconnexion ; liens de désabonnement signés (HMAC, clé privée du site).
