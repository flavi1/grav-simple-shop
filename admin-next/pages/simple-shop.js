/*
 * Page « Boutique » d'Admin2 (Grav 2.0) — plugin simple-shop.
 *
 * Composant web chargé par Admin2 (GET /gpm/plugins/simple-shop/page-script) ;
 * Admin2 définit window.__GRAV_PAGE_TAG (nom de la balise), ainsi que
 * window.__GRAV_API_SERVER_URL, __GRAV_API_PREFIX et __GRAV_API_TOKEN.
 *
 * Trois onglets, tous servis par l'API /simple-shop/* (ShopApiController) :
 *   - Commandes     : liste filtrable, détail, changement de statut + historique,
 *                     facture (statut e-reporting, PDF), export CSV comptable ;
 *   - Transporteurs : édition du YAML des modes de livraison (validé côté serveur) ;
 *   - Bannis        : liste des adresses e-mail bannies.
 *
 * Aucune donnée n'est insérée via innerHTML : tout passe par textContent
 * (pas d'injection possible depuis un nom de client ou un titre de produit).
 */
(function () {
    'use strict';

    var TAG = window.__GRAV_PAGE_TAG || 'grav-simple-shop--page';
    if (customElements.get(TAG)) {
        return;
    }

    var PER_PAGE = 25;

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /** Construit un élément : h('div', {class: 'x', onclick: fn}, enfants…). */
    function h(tag, attrs) {
        var el = document.createElement(tag);
        var children = Array.prototype.slice.call(arguments, 2);

        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key.indexOf('on') === 0 && typeof value === 'function') {
                el.addEventListener(key.slice(2), value);
            } else if (key === 'value') {
                el.value = value;
            } else if (value === true) {
                el.setAttribute(key, '');
            } else {
                el.setAttribute(key, String(value));
            }
        });

        children.forEach(function append(child) {
            if (Array.isArray(child)) {
                child.forEach(append);
            } else if (child !== null && child !== undefined && child !== false) {
                el.appendChild(typeof child === 'object' ? child : document.createTextNode(String(child)));
            }
        });

        return el;
    }

    function money(value) {
        return Number(value || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
    }

    function dateTime(value) {
        if (!value) {
            return '';
        }
        var d = new Date(String(value).replace(' ', 'T') + 'Z');

        return isNaN(d.getTime()) ? String(value) : d.toLocaleString('fr-FR');
    }

    function apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + '/simple-shop' + path;
    }

    /** Appel API ; retourne le JSON (ou le Blob si opts.blob). */
    function api(method, path, body, opts) {
        opts = opts || {};
        var headers = { 'Accept': 'application/json', 'X-API-Token': window.__GRAV_API_TOKEN || '' };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        return fetch(apiUrl(path), {
            method: method,
            headers: headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.ok) {
                if (opts.blob) {
                    return response.blob();
                }

                return response.status === 204 ? {} : response.json();
            }

            return response.json().catch(function () { return {}; }).then(function (problem) {
                var message = response.status === 401
                    ? 'Session expirée : rechargez la page pour vous reconnecter.'
                    : (problem.detail || problem.title || ('Erreur ' + response.status));
                var details = [];
                Object.keys(problem.errors || {}).forEach(function (field) {
                    details = details.concat(problem.errors[field]);
                });
                var error = new Error(message);
                error.details = details;
                throw error;
            });
        });
    }

    function download(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = h('a', { href: url, download: filename });
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    var STYLE = [
        '.ss{font-size:.875rem;color:var(--foreground,#111827)}',
        '.ss *{box-sizing:border-box}',
        '.ss-tabs{display:flex;gap:.25rem;border-bottom:1px solid var(--border,#e5e7eb);margin-bottom:1rem}',
        '.ss-tab{padding:.5rem .9rem;border:0;background:none;cursor:pointer;color:var(--muted-foreground,#6b7280);border-bottom:2px solid transparent;font:inherit}',
        '.ss-tab[aria-selected=true]{color:var(--foreground,#111827);border-bottom-color:var(--primary,#2563eb);font-weight:600}',
        '.ss-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-bottom:1rem}',
        '.ss-field{display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;color:var(--muted-foreground,#6b7280)}',
        '.ss input,.ss select,.ss textarea{font:inherit;padding:.4rem .55rem;border:1px solid var(--border,#d1d5db);border-radius:.375rem;background:var(--background,#fff);color:inherit}',
        '.ss button.ss-btn{font:inherit;padding:.4rem .8rem;border:1px solid var(--border,#d1d5db);border-radius:.375rem;background:var(--background,#fff);color:inherit;cursor:pointer}',
        '.ss button.ss-primary{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:var(--primary-foreground,#fff)}',
        '.ss button:disabled{opacity:.5;cursor:wait}',
        '.ss table{width:100%;border-collapse:collapse}',
        '.ss th{text-align:left;font-weight:600;color:var(--muted-foreground,#6b7280);padding:.45rem .6rem;border-bottom:1px solid var(--border,#e5e7eb)}',
        '.ss td{padding:.5rem .6rem;border-bottom:1px solid var(--border,#f3f4f6);vertical-align:top}',
        '.ss tr.ss-row{cursor:pointer}',
        '.ss tr.ss-row:hover{background:var(--accent,#f9fafb)}',
        '.ss-num{text-align:right;white-space:nowrap}',
        '.ss-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.75rem;background:var(--accent,#eef2ff)}',
        '.ss-badge.paid{background:#dbeafe}.ss-badge.processing{background:#fef3c7}.ss-badge.shipped{background:#d1fae5}',
        '.ss-badge.delivered{background:#bbf7d0}.ss-badge.cancelled,.ss-badge.refunded{background:#fee2e2}',
        '.ss-card{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;padding:1rem;margin-top:1rem;background:var(--card,var(--background,#fff))}',
        '.ss-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem}',
        '.ss h3{margin:0 0 .5rem;font-size:.95rem}',
        '.ss-msg{padding:.6rem .8rem;border-radius:.375rem;margin-bottom:.8rem}',
        '.ss-msg.ok{background:#dcfce7}.ss-msg.err{background:#fee2e2}',
        '.ss-muted{color:var(--muted-foreground,#6b7280)}',
        '.ss textarea.ss-yaml{width:100%;min-height:26rem;font-family:ui-monospace,Menlo,monospace;font-size:.8rem}',
        '.ss ul.ss-list{margin:0;padding-left:1.1rem}'
    ].join('\n');

    // ------------------------------------------------------------------
    // Composant
    // ------------------------------------------------------------------

    class SimpleShopPage extends HTMLElement {
        connectedCallback() {
            this.tab = 'orders';
            this.filters = { status: '', q: '', date_from: '', date_to: '' };
            this.page = 1;
            this.root = h('div', { class: 'ss' });
            this.innerHTML = '';
            this.appendChild(h('style', {}, STYLE));
            this.appendChild(this.root);
            this.draw();
        }

        /** Message d'information / d'erreur en tête de page. */
        notify(text, isError, details) {
            this.message = text ? { text: text, error: !!isError, details: details || [] } : null;
            this.drawMessage();
        }

        drawMessage() {
            if (!this.messageBox) {
                return;
            }
            this.messageBox.innerHTML = '';
            if (this.message) {
                this.messageBox.appendChild(h('div', { class: 'ss-msg ' + (this.message.error ? 'err' : 'ok'), role: 'alert' },
                    this.message.text,
                    this.message.details.length ? h('ul', { class: 'ss-list' }, this.message.details.map(function (d) { return h('li', {}, d); })) : null));
            }
        }

        fail(error) {
            this.notify(error.message, true, error.details);
        }

        draw() {
            var self = this;
            var tabs = [['orders', 'Commandes'], ['carriers', 'Transporteurs'], ['bans', 'Adresses bannies']];

            this.root.innerHTML = '';
            this.messageBox = h('div');
            this.body = h('div');

            this.root.appendChild(h('div', { class: 'ss-tabs', role: 'tablist' }, tabs.map(function (t) {
                return h('button', {
                    class: 'ss-tab', role: 'tab', type: 'button', 'aria-selected': self.tab === t[0] ? 'true' : 'false',
                    onclick: function () { self.tab = t[0]; self.message = null; self.draw(); }
                }, t[1]);
            })));
            this.root.appendChild(this.messageBox);
            this.root.appendChild(this.body);
            this.drawMessage();

            if (this.tab === 'orders') { this.showOrders(); }
            if (this.tab === 'carriers') { this.showCarriers(); }
            if (this.tab === 'bans') { this.showBans(); }
        }

        // ---------------- Commandes ----------------

        queryString(extra) {
            var params = [];
            var self = this;
            Object.keys(this.filters).forEach(function (k) {
                if (self.filters[k]) { params.push(k + '=' + encodeURIComponent(self.filters[k])); }
            });
            Object.keys(extra || {}).forEach(function (k) { params.push(k + '=' + encodeURIComponent(extra[k])); });

            return params.length ? '?' + params.join('&') : '';
        }

        showOrders() {
            var self = this;
            var f = this.filters;
            var statuses = { paid: 'Payée', processing: 'En préparation', shipped: 'Expédiée', delivered: 'Livrée', cancelled: 'Annulée', refunded: 'Remboursée' };

            var statusSelect = h('select', { id: 'ss-f-status' }, h('option', { value: '' }, 'Tous les statuts'),
                Object.keys(statuses).map(function (k) { return h('option', { value: k, selected: f.status === k }, statuses[k]); }));
            var search = h('input', { type: 'search', placeholder: 'Référence, nom, e-mail, téléphone', value: f.q, id: 'ss-f-q' });
            var from = h('input', { type: 'date', value: f.date_from, id: 'ss-f-from' });
            var to = h('input', { type: 'date', value: f.date_to, id: 'ss-f-to' });

            function apply() {
                f.status = statusSelect.value; f.q = search.value.trim(); f.date_from = from.value; f.date_to = to.value;
                self.page = 1;
                self.loadOrders();
            }

            var exportBtn = h('button', {
                class: 'ss-btn', type: 'button',
                onclick: function () {
                    exportBtn.disabled = true;
                    api('GET', '/orders/export' + self.queryString(), undefined, { blob: true })
                        .then(function (blob) { download(blob, 'commandes-' + new Date().toISOString().slice(0, 10) + '.csv'); })
                        .catch(function (e) { self.fail(e); })
                        .then(function () { exportBtn.disabled = false; });
                }
            }, 'Exporter en CSV');

            this.body.appendChild(h('form', { class: 'ss-bar', onsubmit: function (e) { e.preventDefault(); apply(); } },
                h('label', { class: 'ss-field', for: 'ss-f-status' }, 'Statut', statusSelect),
                h('label', { class: 'ss-field', for: 'ss-f-q' }, 'Recherche', search),
                h('label', { class: 'ss-field', for: 'ss-f-from' }, 'Du', from),
                h('label', { class: 'ss-field', for: 'ss-f-to' }, 'Au', to),
                h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Filtrer'),
                exportBtn));

            this.list = h('div');
            this.detail = h('div');
            this.body.appendChild(this.list);
            this.body.appendChild(this.detail);
            this.loadOrders();
        }

        loadOrders() {
            var self = this;
            this.list.innerHTML = '';
            this.list.appendChild(h('p', { class: 'ss-muted' }, 'Chargement…'));

            api('GET', '/orders' + this.queryString({ page: this.page, per_page: PER_PAGE })).then(function (res) {
                var rows = res.data || [];
                var meta = res.meta || { total: rows.length, page: 1, per_page: PER_PAGE };
                var pages = Math.max(1, Math.ceil(meta.total / meta.per_page));

                self.list.innerHTML = '';
                if (!rows.length) {
                    self.list.appendChild(h('p', { class: 'ss-muted' }, 'Aucune commande ne correspond.'));
                    return;
                }

                self.list.appendChild(h('table', {},
                    h('thead', {}, h('tr', {}, ['Référence', 'Date', 'Client', 'Total', 'Statut'].map(function (c, i) {
                        return h('th', { class: i === 3 ? 'ss-num' : '' }, c);
                    }))),
                    h('tbody', {}, rows.map(function (o) {
                        return h('tr', { class: 'ss-row', tabindex: 0, onclick: function () { self.openOrder(o.order_reference); },
                            onkeydown: function (e) { if (e.key === 'Enter') { self.openOrder(o.order_reference); } } },
                            h('td', {}, o.order_reference),
                            h('td', {}, dateTime(o.created_at)),
                            h('td', {}, o.customer_name, h('div', { class: 'ss-muted' }, o.customer_email)),
                            h('td', { class: 'ss-num' }, money(o.total_amount)),
                            h('td', {}, h('span', { class: 'ss-badge ' + o.status }, o.status_label)));
                    }))));

                self.list.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem;align-items:center' },
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page <= 1, onclick: function () { self.page--; self.loadOrders(); } }, 'Précédent'),
                    h('span', { class: 'ss-muted' }, 'Page ' + meta.page + ' / ' + pages + ' — ' + meta.total + ' commande(s)'),
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page >= pages, onclick: function () { self.page++; self.loadOrders(); } }, 'Suivant')));
            }).catch(function (e) { self.list.innerHTML = ''; self.fail(e); });
        }

        openOrder(reference) {
            var self = this;
            this.detail.innerHTML = '';
            this.detail.appendChild(h('p', { class: 'ss-muted' }, 'Chargement de ' + reference + '…'));

            api('GET', '/orders/' + encodeURIComponent(reference)).then(function (res) {
                self.renderOrder(res.data);
                self.detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }).catch(function (e) { self.detail.innerHTML = ''; self.fail(e); });
        }

        renderOrder(o) {
            var self = this;
            var c = o.customer || {};
            var ship = o.shipping || {};
            var b = o.billing || {};

            function address(a) {
                return [a.name, a.street, a['address-complement'], ((a['postal-code'] || '') + ' ' + (a.city || '')).trim()]
                    .filter(Boolean).map(function (line) { return h('div', {}, line); });
            }

            var statusSelect = h('select', { id: 'ss-new-status' }, Object.keys(o.statuses).map(function (k) {
                return h('option', { value: k, selected: k === o.status }, o.statuses[k]);
            }));
            var statusBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                statusBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/status', { status: statusSelect.value })
                    .then(function (res) {
                        self.notify('Statut de ' + o.order_reference + ' : ' + res.data.status_label + '.', false);
                        self.loadOrders();
                        self.openOrder(o.order_reference);
                    })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { statusBtn.disabled = false; });
            } }, 'Enregistrer le statut');

            var inv = o.invoice || {};
            var invOptions = inv.ereporting_options || {};
            var invSelect = h('select', { id: 'ss-inv-status' }, Object.keys(invOptions).map(function (k) {
                return h('option', { value: k, selected: k === inv.ereporting_status }, invOptions[k]);
            }));
            var invBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                invBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/invoice-status', { status: invSelect.value })
                    .then(function (res) { self.notify('E-reporting : ' + res.data.ereporting_label + '.', false); self.openOrder(o.order_reference); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { invBtn.disabled = false; });
            } }, 'Enregistrer');
            var pdfBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                pdfBtn.disabled = true;
                api('GET', '/orders/' + encodeURIComponent(o.order_reference) + '/invoice', undefined, { blob: true })
                    .then(function (blob) { download(blob, (inv.number || o.order_reference) + '.pdf'); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { pdfBtn.disabled = false; });
            } }, 'Télécharger la facture (PDF)');

            this.detail.innerHTML = '';
            this.detail.appendChild(h('div', { class: 'ss-card' },
                h('h3', {}, 'Commande ' + o.order_reference + ' — ' + dateTime(o.created_at)),
                h('div', { class: 'ss-grid' },
                    h('div', {}, h('h3', {}, 'Client'),
                        h('div', {}, ((c.firstname || '') + ' ' + (c.lastname || '')).trim()),
                        h('div', {}, c.email), h('div', {}, c.tel),
                        h('div', { style: 'margin-top:.5rem' }, address(c))),
                    h('div', {}, h('h3', {}, 'Facturation'), address(b).length ? address(b) : h('span', { class: 'ss-muted' }, 'Identique à la livraison')),
                    h('div', {}, h('h3', {}, 'Livraison'), h('div', {}, ship.name || ship.key || ''), h('div', {}, money(ship.price)))),

                h('h3', { style: 'margin-top:1rem' }, 'Articles'),
                h('table', {}, h('tbody', {}, o.items.map(function (i) {
                    return h('tr', {}, h('td', {}, i.title), h('td', { class: 'ss-num' }, i.quantity + ' × ' + money(i.price)),
                        h('td', { class: 'ss-num' }, money(i.quantity * i.price)));
                }).concat([h('tr', {}, h('td', {}, h('strong', {}, 'Total TTC (livraison comprise)')), h('td'), h('td', { class: 'ss-num' }, h('strong', {}, money(o.total_amount))))]))),

                h('div', { class: 'ss-grid', style: 'margin-top:1rem' },
                    h('div', {}, h('h3', {}, 'Statut'),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-new-status' }, 'Nouveau statut', statusSelect), statusBtn),
                        h('p', { class: 'ss-muted' }, 'Un e-mail est envoyé au client pour : expédiée, annulée, remboursée.')),
                    h('div', {}, h('h3', {}, 'Historique'),
                        h('ul', { class: 'ss-list' }, o.history.map(function (e) {
                            return h('li', {}, dateTime(e.changed_at) + ' — ' + e.new_status_label + (e.changed_by ? ' (par ' + e.changed_by + ')' : ' (automatique)'));
                        }))),
                    h('div', {}, h('h3', {}, 'Facture ' + (inv.number || '')),
                        h('p', {}, 'E-reporting : ', h('strong', {}, inv.ereporting_label || '—')),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-inv-status' }, 'Statut e-reporting', invSelect), invBtn),
                        pdfBtn))));
        }

        // ---------------- Transporteurs ----------------

        showCarriers() {
            var self = this;
            var area = h('textarea', { class: 'ss-yaml', spellcheck: 'false', 'aria-label': 'Configuration YAML des transporteurs' });
            var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                saveBtn.disabled = true;
                api('PUT', '/carriers', { yaml: area.value })
                    .then(function () { self.notify('Transporteurs enregistrés (l\'ancienne version est conservée en carriers.yaml.bak).', false); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { saveBtn.disabled = false; });
            } }, 'Enregistrer');

            this.body.appendChild(h('p', { class: 'ss-muted' },
                'Modes de livraison et grilles de tarifs (France métropolitaine hors Corse : zone FR-METRO). '
                + 'Le texte est vérifié avant enregistrement ; les erreurs sont listées en haut de page.'));
            var legalBox = h('div', { class: 'ss-msg ok', role: 'note' });
            this.body.appendChild(legalBox);
            this.body.appendChild(area);
            this.body.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem' }, saveBtn));

            api('GET', '/carriers').then(function (res) {
                area.value = res.data.yaml;
                var law = (res.data.legal || {}).book_law || {};
                var pct = ((res.data.legal || {}).vat_rate || 0) * 100;
                legalBox.textContent = law.enabled
                    ? 'Plancher légal (loi Darcos) : les frais de livraison de livres ne peuvent pas être inférieurs à '
                        + money(law.below) + ' TTC pour une commande de moins de ' + money(law.threshold)
                        + ' TTC, ni à ' + money(law.at_or_above) + ' TTC au-delà. Les tarifs ci-dessous sont des TTC (TVA '
                        + pct.toLocaleString('fr-FR') + ' %). Réglages : Plugins > Simple Shop > Livraison. '
                        + 'Un mode avec « legal_floor: false » (retrait sur place) y échappe.'
                    : 'Plancher légal des frais de livraison de livres désactivé (Plugins > Simple Shop > Livraison).';
            })
                .catch(function (e) { self.fail(e); });
        }

        // ---------------- Adresses bannies ----------------

        showBans() {
            var self = this;
            var listBox = h('div');
            var input = h('input', { type: 'email', placeholder: 'adresse@exemple.fr', required: true, id: 'ss-ban-email' });

            function draw(emails) {
                listBox.innerHTML = '';
                if (!emails.length) {
                    listBox.appendChild(h('p', { class: 'ss-muted' }, 'Aucune adresse bannie.'));
                    return;
                }
                listBox.appendChild(h('table', {}, h('tbody', {}, emails.map(function (email) {
                    return h('tr', {}, h('td', {}, email), h('td', { class: 'ss-num' }, h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        if (!window.confirm('Débannir ' + email + ' ?')) { return; }
                        api('DELETE', '/bans/' + encodeURIComponent(email))
                            .then(function (res) { self.notify(email + ' n\'est plus bannie.', false); draw(res.data); })
                            .catch(function (e) { self.fail(e); });
                    } }, 'Débannir')));
                }))));
            }

            this.body.appendChild(h('p', { class: 'ss-muted' },
                'Une adresse bannie ne peut plus recevoir de code de connexion ni passer commande. '
                + 'Bannir efface aussi son profil enregistré (nom, adresse, téléphone) ; la désinscription newsletter est conservée.'));
            this.body.appendChild(h('form', { class: 'ss-bar', onsubmit: function (e) {
                e.preventDefault();
                var email = input.value.trim();
                if (!window.confirm('Bannir ' + email + ' ? Son profil enregistré sera effacé.')) { return; }
                api('POST', '/bans', { email: email })
                    .then(function (res) { input.value = ''; self.notify(email + ' est bannie.', false); draw(res.data); })
                    .catch(function (err) { self.fail(err); });
            } }, h('label', { class: 'ss-field', for: 'ss-ban-email' }, 'Adresse à bannir', input),
                h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Bannir')));
            this.body.appendChild(listBox);

            api('GET', '/bans').then(function (res) { draw(res.data); }).catch(function (e) { self.fail(e); });
        }
    }

    customElements.define(TAG, SimpleShopPage);
})();
