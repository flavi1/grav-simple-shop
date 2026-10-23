/*
 * Page « Ventes » d'Admin2 (Grav 2.0) — plugin simple-shop.
 *
 * Composant web chargé par Admin2 (GET /gpm/plugins/simple-shop/page-script) ;
 * Admin2 définit window.__GRAV_PAGE_TAG (nom de la balise), ainsi que
 * window.__GRAV_API_SERVER_URL, __GRAV_API_PREFIX et __GRAV_API_TOKEN.
 *
 * Trois onglets, tous servis par l'API /simple-shop/* (ShopApiController) :
 *   - Commandes     : liste filtrable, détail, changement de statut + historique,
 *                     facture (statut e-reporting, PDF), export CSV comptable ;
 *   - Transporteurs : modes (forfait, tarif du plugin, grille), contraintes globales, simulateur, YAML avancé ;
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

    /** Montant en centimes → « 12,50 € ». */
    function cents(value, currency) {
        return (Number(value || 0) / 100).toLocaleString('fr-FR', { style: 'currency', currency: currency || 'EUR' });
    }

    /** Pastille de l'état de règlement (à régler, partiellement réglée, réglée…), avec le reste dû s'il y en a un. */
    function settlementBadge(st) {
        if (!st) {
            return '';
        }
        return h('span', {}, h('span', { class: 'ss-pay ' + st.state }, st.label),
            st.remaining_cents > 0 && st.state !== 'unpaid' ? h('div', { class: 'ss-muted' }, 'reste ' + cents(st.remaining_cents, st.currency)) : null);
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
        // Couleurs d'état : fond ET texte toujours fixés ensemble (lisibles quel que soit le thème d'Admin2).
        '.ss{--ss-ok-bg:#dcfce7;--ss-ok-fg:#14532d;--ss-err-bg:#fee2e2;--ss-err-fg:#7f1d1d;--ss-warn-bg:#fef3c7;--ss-warn-fg:#78350f;'
            + '--ss-info-bg:#e0e7ff;--ss-info-fg:#1e1b4b;--ss-blue-bg:#dbeafe;--ss-blue-fg:#1e3a8a;--ss-n-bg:#f3f4f6;--ss-n-fg:#1f2937}',
        '.ss[data-ss-theme=dark]{--ss-ok-bg:#14532d;--ss-ok-fg:#dcfce7;--ss-err-bg:#7f1d1d;--ss-err-fg:#fee2e2;--ss-warn-bg:#78350f;--ss-warn-fg:#fef3c7;'
            + '--ss-info-bg:#312e81;--ss-info-fg:#e0e7ff;--ss-blue-bg:#1e3a8a;--ss-blue-fg:#dbeafe;--ss-n-bg:#374151;--ss-n-fg:#f3f4f6}',
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
        '.ss-cat td{vertical-align:top}.ss-cat .ss-stack{display:flex;flex-direction:column;gap:.3rem}',
        '.ss-cat .ss-cell{display:flex;flex-direction:column;gap:.1rem}.ss-cat .ss-cell small{opacity:.75;font-size:.72rem}',
        '.ss-cat input,.ss-cat select,.ss-cat textarea{font:inherit;padding:.25rem .4rem;border:1px solid var(--border,#d1d5db);border-radius:.3rem;background:transparent;color:inherit}',
        '.ss-cat input:invalid,.ss-cat [aria-invalid=true]{border-color:#dc2626;outline-color:#dc2626}',
        '.ss-cat .ss-unit{display:flex;align-items:center;gap:.25rem}.ss-cat tr.ss-dirty{background:var(--ss-warn-bg)}.ss-cat tr.ss-dirty td{color:var(--ss-warn-fg)}',
        '.ss-cat .ss-ferr{color:#dc2626;font-size:.75rem}.ss-cat th{position:sticky;top:0;background:var(--background,#fff);z-index:1}',
        '.ss-cat td:first-child,.ss-cat th:first-child{position:sticky;left:0;background:var(--background,#fff);z-index:2}.ss-cat th:first-child{z-index:3}',
        '.ss-cat tr.ss-dirty td:first-child{background:var(--ss-warn-bg)}',
        '.ss-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.75rem;background:var(--ss-info-bg);color:var(--ss-info-fg)}',
        '.ss-badge.paid{background:var(--ss-blue-bg);color:var(--ss-blue-fg)}.ss-badge.processing{background:var(--ss-warn-bg);color:var(--ss-warn-fg)}',
        '.ss-badge.shipped,.ss-badge.delivered{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}',
        '.ss-badge.cancelled,.ss-badge.refunded{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-badge.pending,.ss-badge.draft{background:var(--ss-n-bg);color:var(--ss-n-fg)}.ss-badge.completed{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}',
        // État de règlement (calculé à partir des paiements)
        '.ss-pay{display:inline-block;padding:.1rem .5rem;border-radius:.375rem;font-size:.75rem;background:var(--ss-n-bg);color:var(--ss-n-fg)}',
        '.ss-pay.paid{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-pay.partial{background:var(--ss-warn-bg);color:var(--ss-warn-fg)}',
        '.ss-pay.unpaid{background:var(--ss-err-bg);color:var(--ss-err-fg)}.ss-pay.overpaid{background:var(--ss-info-bg);color:var(--ss-info-fg)}',
        '.ss td.ss-neg{color:var(--ss-err-fg)}',
        '.ss-card{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;padding:1rem;margin-top:1rem;background:var(--card,var(--background,#fff))}',
        '.ss-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1rem}',
        '.ss h3{margin:0 0 .5rem;font-size:.95rem}',
        '.ss-msg{padding:.6rem .8rem;border-radius:.375rem;margin-bottom:.8rem}',
        '.ss-msg.ok{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-msg.err{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-msg.info{background:var(--ss-info-bg);color:var(--ss-info-fg)}',
        '.ss-muted{color:var(--muted-foreground,#6b7280)}',
        '.ss textarea.ss-yaml{width:100%;min-height:26rem;font-family:ui-monospace,Menlo,monospace;font-size:.8rem}',
        '.ss ul.ss-list{margin:0;padding-left:1.1rem}',
        '.ss-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
        '.ss :focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:2px}',
        '.ss-section{margin-top:1.5rem}.ss-section:first-of-type{margin-top:.5rem}',
        '.ss-provider{padding:.5rem 0;border-bottom:1px solid var(--border,#e5e7eb)}',
        '.ss-ok,.ss-ko{display:inline-block;padding:.1rem .5rem;border-radius:.375rem}',
        '.ss-ok{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-ko{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss details.ss-mode,.ss details.ss-advanced{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;margin:.6rem 0;background:var(--card,var(--background,#fff))}',
        '.ss details>summary{cursor:pointer;padding:.7rem .9rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center}',
        '.ss-mode-name{font-weight:600}',
        '.ss-badge.ss-b-on{background:var(--ss-ok-bg);color:var(--ss-ok-fg)}.ss-badge.ss-b-off{background:var(--ss-n-bg);color:var(--ss-n-fg)}',
        '.ss-badge.ss-b-ko{background:var(--ss-err-bg);color:var(--ss-err-fg)}',
        '.ss-done{display:block;padding:.4rem .6rem;border-radius:.375rem;background:var(--ss-ok-bg);color:var(--ss-ok-fg)}',
        '.ss-done:empty{display:none}',
        '.ss-mode-form{padding:0 .9rem .9rem}',
        '.ss-mode-form fieldset{border:1px solid var(--border,#e5e7eb);border-radius:.375rem;margin:.8rem 0;padding:.6rem .8rem}',
        '.ss-mode-form legend{font-weight:600;padding:0 .3rem}','.ss-mode-form fieldset{min-width:0}',
        '.ss-field-row{display:flex;flex-direction:column;gap:.2rem;margin:.4rem 0}',
        '.ss-field-row label{font-size:.8rem}',
        '.ss-field-row.ss-check{flex-direction:row;align-items:center;gap:.5rem}',
        '.ss-inline{display:flex;flex-wrap:wrap;gap:1rem}',
        '.ss-table-scroll{overflow-x:auto;margin:.5rem 0}',
        '.ss-table-scroll table input,.ss-table-scroll table select{width:7.5rem}',
        '.ss-new-mode{margin-top:1.2rem}.ss-new-mode h4{margin:0 0 .4rem}',
        '.ss-form-status{min-height:1.2rem;margin:.4rem 0 0}',
        '.ss-advanced>*:not(summary){padding:0 .9rem .9rem}',
        '.ss-hide-adv .ss-adv-col{display:none}',
        '.ss [hidden]{display:none !important}',
        '.ss details.ss-grid-details{border:1px solid var(--border,#e5e7eb);border-radius:.375rem;margin:.4rem 0 .8rem}',
        '.ss details.ss-grid-details>*:not(summary){margin-left:.9rem;margin-right:.9rem}',
        '.ss-choice label{font-size:.875rem}',
        '.ss-pricing-body{margin:.2rem 0 .6rem}',
        '.ss fieldset.ss-constraint{border:1px solid var(--border,#e5e7eb);border-radius:.5rem;margin:.8rem 0;padding:.6rem .9rem;min-width:0}',
        '.ss fieldset.ss-constraint>legend{font-weight:600;padding:0 .3rem}',
        '.ss fieldset.ss-constraint fieldset,.ss fieldset.ss-sim-types{border:0;padding:0;margin:.4rem 0;min-width:0}',
        '.ss fieldset.ss-constraint fieldset>legend,.ss fieldset.ss-sim-types>legend{font-size:.8rem;padding:0}',
        '.ss input.ss-comment{width:18rem}',
        '@media (prefers-reduced-motion:no-preference){.ss details{transition:none}}'
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
            this.watchTheme();
            this.invoicing = null;
            this.docFilters = { kind: '', state: '', q: '' };
            this.docPage = 1;
            this.draw();
            // Ventes manuelles (factures, acomptes, demandes de paiement) : onglet affiché si l'option est activée.
            var self = this;
            api('GET', '/invoicing').then(function (res) {
                self.invoicing = res.data || null;
                if (self.invoicing && self.invoicing.enabled) { self.draw(); }
            }).catch(function () { self.invoicing = null; });
            // Catalogue : onglet affiché si un type de produit déclare un tableau (shop.admin_table de son blueprint).
            this.catalogTypes = [];
            this.catalogType = '';
            api('GET', '/catalog?types_only=1').then(function (res) {
                self.catalogTypes = (res.data && res.data.types) || [];
                if (self.catalogTypes.length) { self.draw(); }
            }).catch(function () { self.catalogTypes = []; });
        }

        disconnectedCallback() {
            if (this.themeObserver) {
                this.themeObserver.disconnect();
            }
        }

        /**
         * Admin2 peut être en thème clair ou sombre : on lit la couleur du texte
         * héritée (claire = thème sombre) et on bascule nos couleurs d'état.
         * Ré-évalué quand <html>/<body> changent de classe ou d'attribut de thème.
         */
        watchTheme() {
            var self = this;
            function sync() {
                var m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(getComputedStyle(self).color || '');
                var dark = m ? (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 > 0.5 : false;
                self.root.setAttribute('data-ss-theme', dark ? 'dark' : 'light');
            }
            sync();
            if (window.MutationObserver) {
                this.themeObserver = new MutationObserver(function () { window.requestAnimationFrame(sync); });
                [document.documentElement, document.body].forEach(function (node) {
                    self.themeObserver.observe(node, { attributes: true, attributeFilter: ['class', 'style', 'data-theme', 'data-mode'] });
                });
            }
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                if (mq.addEventListener) { mq.addEventListener('change', function () { window.requestAnimationFrame(sync); }); }
            }
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
                this.messageBox.appendChild(h('div', { class: 'ss-msg ' + (this.message.error ? 'err' : 'ok'), role: this.message.error ? 'alert' : 'status' },
                    this.message.text,
                    this.message.details.length ? h('ul', { class: 'ss-list' }, this.message.details.map(function (d) { return h('li', {}, d); })) : null));
            }
        }

        fail(error) {
            this.notify(error.message, true, error.details);
        }

        draw() {
            var self = this;
            var tabs = [['orders', 'Commandes'], ['carriers', 'Transporteurs'], ['taxes', 'Taxes'], ['bans', 'Adresses bannies']];
            if (this.invoicing && this.invoicing.enabled) {
                tabs.splice(1, 0, ['invoices', 'Factures']);
            }
            if (this.catalogTypes && this.catalogTypes.length) {
                tabs.splice(tabs.length - 3, 0, ['catalog', 'Catalogue']);
            }

            this.root.innerHTML = '';
            this.messageBox = h('div');
            this.body = h('div', { id: 'ss-panel', role: 'tabpanel' });

            this.root.appendChild(h('div', { class: 'ss-tabs', role: 'tablist', 'aria-label': 'Sections des ventes' }, tabs.map(function (t, i) {
                var selected = self.tab === t[0];
                return h('button', {
                    class: 'ss-tab', role: 'tab', type: 'button', id: 'ss-tab-' + t[0], 'aria-controls': 'ss-panel',
                    'aria-selected': selected ? 'true' : 'false', tabindex: selected ? '0' : '-1',
                    onclick: function () { self.tab = t[0]; self.message = null; self.draw(); },
                    onkeydown: function (ev) {
                        var target = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[ev.key];
                        if (target === undefined) { return; }
                        ev.preventDefault();
                        self.tab = tabs[(target + tabs.length) % tabs.length][0];
                        self.message = null;
                        self.draw();
                        var next = self.root.querySelector('#ss-tab-' + self.tab);
                        if (next) { next.focus(); }
                    }
                }, t[1]);
            })));
            this.root.appendChild(this.messageBox);
            this.root.appendChild(this.body);
            this.drawMessage();

            if (this.tab === 'orders') { this.showOrders(); }
            if (this.tab === 'invoices') { this.showInvoices(); }
            if (this.tab === 'catalog') { this.showCatalog(); }
            if (this.tab === 'carriers') { this.showCarriers(); }
            if (this.tab === 'taxes') { this.showTaxes(); }
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
            var statuses = { draft: 'Brouillon', pending: 'En attente', processing: 'En préparation', shipped: 'Expédiée', delivered: 'Livrée',
                completed: 'Terminée', cancelled: 'Annulée', refunded: 'Remboursée' };

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
                    self.list.appendChild(self.newManualButton());
                    return;
                }

                self.list.appendChild(h('table', {},
                    h('thead', {}, h('tr', {}, ['Référence', 'Date', 'Client', 'Total', 'Statut', 'Règlement'].map(function (c, i) {
                        return h('th', { class: i === 3 ? 'ss-num' : '' }, c);
                    }))),
                    h('tbody', {}, rows.map(function (o) {
                        return h('tr', { class: 'ss-row', tabindex: 0, onclick: function () { self.openOrder(o.order_reference); },
                            onkeydown: function (e) { if (e.key === 'Enter') { self.openOrder(o.order_reference); } } },
                            h('td', {}, o.order_reference),
                            h('td', {}, dateTime(o.created_at)),
                            h('td', {}, o.customer_name, h('div', { class: 'ss-muted' }, o.customer_email)),
                            h('td', { class: 'ss-num' }, money(o.total_amount)),
                            h('td', {}, h('span', { class: 'ss-badge ' + o.status }, o.status_label)),
                            h('td', {}, settlementBadge(o.settlement)));
                    }))));

                self.list.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem;align-items:center' },
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page <= 1, onclick: function () { self.page--; self.loadOrders(); } }, 'Précédent'),
                    h('span', { class: 'ss-muted' }, 'Page ' + meta.page + ' / ' + pages + ' — ' + meta.total + ' commande(s)'),
                    h('button', { class: 'ss-btn', type: 'button', disabled: self.page >= pages, onclick: function () { self.page++; self.loadOrders(); } }, 'Suivant')));
                self.list.appendChild(self.newManualButton());
            }).catch(function (e) { self.list.innerHTML = ''; self.fail(e); });
        }

        /** Bouton « Nouvelle commande manuelle » (factures manuelles activées). */
        newManualButton() {
            var self = this;
            if (!this.invoicing || !this.invoicing.enabled) {
                return document.createDocumentFragment(); // rien à afficher (appendChild refuse null)
            }
            return h('div', { class: 'ss-bar', style: 'margin-top:.4rem' },
                h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () { self.openEditor(null, null); } },
                    'Nouvelle commande manuelle (facture, acompte, demande de paiement)'));
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

            var tr = o.tracking || {};
            var trInput = h('input', { type: 'text', id: 'ss-tracking', value: tr.number || '', maxlength: '40', placeholder: 'ex. 6A12345678901' });
            var trBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                trBtn.disabled = true;
                api('PATCH', '/orders/' + encodeURIComponent(o.order_reference) + '/tracking', { tracking_number: trInput.value })
                    .then(function (res) { self.notify(res.data.number ? 'Numéro de suivi enregistré.' : 'Numéro de suivi effacé.', false); self.openOrder(o.order_reference); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { trBtn.disabled = false; });
            } }, 'Enregistrer le suivi');

            var manual = o.source === 'manual';
            this.detail.innerHTML = '';
            this.detail.appendChild(h('div', { class: 'ss-card' },
                h('h3', {}, (manual ? 'Vente manuelle ' : 'Commande ') + o.order_reference + ' — ' + dateTime(o.created_at)),
                this.paymentsSection(o),
                h('div', { class: 'ss-grid' },
                    h('div', {}, h('h3', {}, 'Client'),
                        h('div', {}, ((c.firstname || '') + ' ' + (c.lastname || '')).trim()),
                        h('div', {}, c.email), h('div', {}, c.tel),
                        h('div', { style: 'margin-top:.5rem' }, address(c))),
                    h('div', {}, h('h3', {}, 'Facturation'), address(b).length ? address(b) : h('span', { class: 'ss-muted' }, 'Identique à la livraison')),
                    manual ? null : h('div', {}, h('h3', {}, 'Livraison'), h('div', {}, ship.name || ship.key || ''), h('div', {}, money(ship.price)))),

                h('h3', { style: 'margin-top:1rem' }, 'Articles'),
                h('table', {}, h('tbody', {}, o.items.map(function (i) {
                    // Options de la ligne (JSON enregistré avec la commande), affichées en texte.
                    var opts = {};
                    try { opts = i.options ? JSON.parse(i.options) : {}; } catch (e) { opts = {}; }
                    var optText = Object.keys(opts || {}).map(function (k) { return k + ' : ' + (Array.isArray(opts[k]) ? opts[k].join(', ') : opts[k]); }).join(' · ');
                    return h('tr', {}, h('td', {}, i.title, optText ? h('div', { class: 'ss-muted' }, optText) : ''), h('td', { class: 'ss-num' }, i.quantity + ' × ' + money(i.price)),
                        h('td', { class: 'ss-num' }, money(i.quantity * i.price)));
                }).concat([h('tr', {}, h('td', {}, h('strong', {}, manual ? 'Total TTC' : 'Total TTC (livraison comprise)')), h('td'), h('td', { class: 'ss-num' }, h('strong', {}, money(o.total_amount))))]))),

                h('div', { class: 'ss-grid', style: 'margin-top:1rem' },
                    h('div', {}, h('h3', {}, 'Statut'),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-new-status' }, 'Nouveau statut', statusSelect), statusBtn),
                        h('p', { class: 'ss-muted' }, 'Un e-mail est envoyé au client pour : expédiée, annulée, remboursée.'),
                        manual ? null : h('h3', { style: 'margin-top:1rem' }, 'Suivi du colis'),
                        manual ? null : h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-tracking' }, 'Numéro de suivi', trInput), trBtn),
                        !manual && tr.url ? h('p', {}, h('a', { href: tr.url, target: '_blank', rel: 'noopener noreferrer' }, 'Page de suivi')) : null,
                        manual ? null : h('p', { class: 'ss-muted' }, 'Saisissez le numéro AVANT de passer la commande en « Expédiée » : il figure dans l’e-mail au client.')),
                    h('div', {}, h('h3', {}, 'Historique'),
                        h('ul', { class: 'ss-list' }, o.history.map(function (e) {
                            return h('li', {}, dateTime(e.changed_at) + ' — ' + e.new_status_label + (e.changed_by ? ' (par ' + e.changed_by + ')' : ' (automatique)'));
                        }))),
                    o.source === 'manual' ? null : h('div', {}, h('h3', {}, 'Facture ' + (inv.number || '')),
                        h('p', {}, 'E-reporting : ', h('strong', {}, inv.ereporting_label || '—')),
                        h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-inv-status' }, 'Statut e-reporting', invSelect), invBtn),
                        pdfBtn)),
                o.source === 'manual' ? this.documentsSection(o) : null));
        }

        /**
         * Paiements d'une commande : état de règlement, liste (remboursements en négatif), saisie d'un paiement reçu hors du
         * site (virement, chèque…) ou d'un remboursement.
         */
        paymentsSection(o) {
            var self = this;
            var st = o.settlement || {};
            var methods = o.payment_methods || {};
            var today = new Date().toISOString().slice(0, 10);
            var amount = h('input', { type: 'text', id: 'ss-pay-amount', inputmode: 'decimal', size: '10', placeholder: '0.00',
                value: st.remaining_cents > 0 ? (st.remaining_cents / 100).toFixed(2) : '' });
            var method = h('select', { id: 'ss-pay-method' }, Object.keys(methods).map(function (k) { return h('option', { value: k }, methods[k]); }));
            var date = h('input', { type: 'date', id: 'ss-pay-date', value: today, max: today });
            var ref = h('input', { type: 'text', id: 'ss-pay-ref', maxlength: '120', placeholder: 'ex. référence du virement' });
            var note = h('input', { type: 'text', id: 'ss-pay-note', maxlength: '500' });
            var refund = h('input', { type: 'checkbox', id: 'ss-pay-refund' });
            // Commande manuelle : paiement rattaché à un document (facture, acompte, demande de paiement) ; reçu au client.
            var docs = (o.documents || []).filter(function (d) { return d.kind !== 'credit_note'; });
            var firstDue = docs.filter(function (d) { return d.settlement.remaining_cents > 0; })[0];
            var docSelect = docs.length ? h('select', { id: 'ss-pay-doc' }, docs.map(function (d) {
                return h('option', { value: String(d.id), selected: d === firstDue }, d.kind_label + ' ' + d.number
                    + (d.settlement.remaining_cents > 0 ? ' — reste ' + cents(d.settlement.remaining_cents, d.settlement.currency) : ''));
            })) : null;
            var notify = docs.length ? h('input', { type: 'checkbox', id: 'ss-pay-notify', checked: true }) : null;
            if (docSelect) {
                var syncAmount = function () {
                    var d = docs.filter(function (x) { return String(x.id) === docSelect.value; })[0];
                    if (d && d.settlement.remaining_cents > 0) { amount.value = (d.settlement.remaining_cents / 100).toFixed(2); }
                };
                docSelect.addEventListener('change', syncAmount);
                syncAmount();
            }
            var btn = h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Enregistrer le paiement');
            refund.addEventListener('change', function () { btn.textContent = refund.checked ? 'Enregistrer le remboursement' : 'Enregistrer le paiement'; });

            var form = h('form', { class: 'ss-bar', onsubmit: function (e) {
                e.preventDefault();
                btn.disabled = true;
                api('POST', '/orders/' + encodeURIComponent(o.order_reference) + '/payments', {
                    amount: amount.value, method: method.value, paid_at: date.value, external_ref: ref.value, note: note.value, refund: refund.checked,
                    invoice_id: docSelect ? docSelect.value : null, notify: notify ? notify.checked : false
                }).then(function (res) {
                    self.notify((refund.checked ? 'Remboursement' : 'Paiement') + ' enregistré : ' + res.data.settlement.label + '.', false);
                    self.loadOrders();
                    self.openOrder(o.order_reference);
                }).catch(function (e) { self.fail(e); }).then(function () { btn.disabled = false; });
            } },
                docSelect ? h('label', { class: 'ss-field', for: 'ss-pay-doc' }, 'Document', docSelect) : null,
                h('label', { class: 'ss-field', for: 'ss-pay-amount' }, 'Montant', amount),
                h('label', { class: 'ss-field', for: 'ss-pay-method' }, 'Moyen', method),
                h('label', { class: 'ss-field', for: 'ss-pay-date' }, 'Date', date),
                h('label', { class: 'ss-field', for: 'ss-pay-ref' }, 'Référence', ref),
                h('label', { class: 'ss-field', for: 'ss-pay-note' }, 'Note', note),
                h('label', { class: 'ss-field', for: 'ss-pay-refund', style: 'flex-direction:row;align-items:center;gap:.3rem' }, refund, 'Remboursement'),
                notify ? h('label', { class: 'ss-field', for: 'ss-pay-notify', style: 'flex-direction:row;align-items:center;gap:.3rem' }, notify, 'Envoyer un reçu au client') : null,
                btn);

            var numbers = {};
            (o.documents || []).forEach(function (d) { numbers[d.id] = d.number; });
            var rows = (o.payments || []).map(function (p) {
                return h('tr', {},
                    h('td', {}, dateTime(p.paid_at), p.invoice_id && numbers[p.invoice_id] ? h('div', { class: 'ss-muted' }, numbers[p.invoice_id]) : null),
                    h('td', {}, p.method_label),
                    h('td', { class: 'ss-num' + (p.amount_cents < 0 ? ' ss-neg' : '') }, cents(p.amount_cents, p.currency)),
                    h('td', {}, p.external_ref || ''),
                    h('td', {}, p.note || '', p.recorded_by ? h('div', { class: 'ss-muted' }, 'par ' + p.recorded_by) : null));
            });

            return h('section', { class: 'ss-section', 'aria-labelledby': 'ss-pay-title' },
                h('h3', { id: 'ss-pay-title' }, 'Règlement'),
                h('p', {}, settlementBadge(st), ' ', h('span', { class: 'ss-muted' },
                    'Réglé ' + cents(st.paid_cents, st.currency) + ' sur ' + cents(st.due_cents, st.currency)
                    + (st.refunded_cents ? ' (dont ' + cents(st.refunded_cents, st.currency) + ' remboursés)' : ''))),
                rows.length ? h('table', {}, h('thead', {}, h('tr', {}, ['Date', 'Moyen', 'Montant', 'Référence', 'Note'].map(function (c, i) {
                    return h('th', { class: i === 2 ? 'ss-num' : '' }, c);
                }))), h('tbody', {}, rows)) : h('p', { class: 'ss-muted' }, 'Aucun paiement enregistré.'),
                h('details', { style: 'margin-top:.6rem' }, h('summary', {}, 'Enregistrer un paiement reçu hors du site ou un remboursement'),
                    h('div', { style: 'padding:.6rem 0' }, form)));
        }

        // ---------------- Factures (ventes manuelles) ----------------

        showInvoices() {
            var self = this;
            var f = this.docFilters;
            var inv = this.invoicing || {};
            var kind = h('select', { id: 'ss-d-kind' }, h('option', { value: '' }, 'Tous les types'),
                Object.keys(inv.kinds || {}).map(function (k) { return h('option', { value: k, selected: f.kind === k }, inv.kinds[k]); }));
            var states = { unpaid: 'À régler', partial: 'Partiellement réglée', overdue: 'En retard', paid: 'Réglée', refunded: 'Remboursée' };
            var state = h('select', { id: 'ss-d-state' }, h('option', { value: '' }, 'Tous les états'),
                Object.keys(states).map(function (k) { return h('option', { value: k, selected: f.state === k }, states[k]); }));
            var q = h('input', { type: 'search', id: 'ss-d-q', value: f.q, placeholder: 'Numéro, commande, client' });

            this.body.appendChild(h('form', { class: 'ss-bar', onsubmit: function (e) {
                e.preventDefault();
                f.kind = kind.value; f.state = state.value; f.q = q.value.trim();
                self.docPage = 1;
                self.loadInvoices();
            } },
                h('label', { class: 'ss-field', for: 'ss-d-kind' }, 'Type', kind),
                h('label', { class: 'ss-field', for: 'ss-d-state' }, 'Règlement', state),
                h('label', { class: 'ss-field', for: 'ss-d-q' }, 'Recherche', q),
                h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Filtrer')));
            this.list = h('div');
            this.detail = h('div');
            this.body.appendChild(this.list);
            this.body.appendChild(this.detail);
            this.loadInvoices();
        }

        loadInvoices() {
            var self = this;
            var f = this.docFilters;
            var qs = '?page=' + this.docPage + '&per_page=' + PER_PAGE + Object.keys(f).map(function (k) { return f[k] ? '&' + k + '=' + encodeURIComponent(f[k]) : ''; }).join('');
            this.list.innerHTML = '';
            this.list.appendChild(h('p', { class: 'ss-muted' }, 'Chargement…'));
            api('GET', '/documents' + qs).then(function (res) {
                var rows = res.data || [];
                var meta = res.meta || { total: rows.length, page: 1, per_page: PER_PAGE };
                var pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
                self.list.innerHTML = '';
                if (!rows.length) {
                    self.list.appendChild(h('p', { class: 'ss-muted' }, 'Aucun document ne correspond.'));
                } else {
                    self.list.appendChild(h('table', {},
                        h('thead', {}, h('tr', {}, ['Numéro', 'Type', 'Client', 'Émis le', 'Échéance', 'Montant', 'Règlement', 'Transmission'].map(function (c, i) {
                            return h('th', { class: i === 5 ? 'ss-num' : '' }, c);
                        }))),
                        h('tbody', {}, rows.map(function (d) {
                            var open = function () { self.openOrder(d.order_reference); };
                            return h('tr', { class: 'ss-row', tabindex: 0, onclick: open, onkeydown: function (e) { if (e.key === 'Enter') { open(); } } },
                                h('td', {}, d.number, h('div', { class: 'ss-muted' }, d.order_reference)),
                                h('td', {}, d.kind_label),
                                h('td', {}, d.customer_name, h('div', { class: 'ss-muted' }, d.customer_email)),
                                h('td', {}, dateTime(d.issued_at)),
                                h('td', { class: d.overdue ? 'ss-neg' : '' }, d.due_date ? new Date(d.due_date + 'T12:00:00Z').toLocaleDateString('fr-FR') : '', d.overdue ? ' (en retard)' : ''),
                                h('td', { class: 'ss-num' }, cents(d.total_cents, d.settlement.currency)),
                                h('td', {}, settlementBadge(d.settlement)),
                                h('td', {}, d.ereporting_label));
                        }))));
                    self.list.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem;align-items:center' },
                        h('button', { class: 'ss-btn', type: 'button', disabled: self.docPage <= 1, onclick: function () { self.docPage--; self.loadInvoices(); } }, 'Précédent'),
                        h('span', { class: 'ss-muted' }, 'Page ' + meta.page + ' / ' + pages + ' — ' + meta.total + ' document(s)'),
                        h('button', { class: 'ss-btn', type: 'button', disabled: self.docPage >= pages, onclick: function () { self.docPage++; self.loadInvoices(); } }, 'Suivant')));
                }
                self.list.appendChild(self.newManualButton());
            }).catch(function (e) { self.list.innerHTML = ''; self.fail(e); });
        }

        /** Recharge la liste de l'onglet courant (commandes ou documents). */
        reloadList() {
            if (this.tab === 'invoices') { this.loadInvoices(); } else { this.loadOrders(); }
        }

        /**
         * Éditeur d'une commande manuelle (brouillon) : client (pré-rempli d'après l'e-mail), lignes libres, conditions.
         * reference = null : nouvelle commande ; draft = saisie enregistrée (réouverture).
         */
        openEditor(reference, draft) {
            var self = this;
            var inv = this.invoicing || {};
            var d = draft || {};
            var c = d.customer || {};
            var uid = 0;
            function field(label, input, hint) {
                var id = input.id || ('ss-ed-' + (++uid));
                input.id = id;
                return h('label', { class: 'ss-field', for: id }, label, input, hint ? h('span', { class: 'ss-muted' }, hint) : null);
            }
            function text(name, value, attrs) { return h('input', Object.assign({ type: 'text', value: value || '', 'data-k': name }, attrs || {})); }

            // --- Client
            var type = c.type === 'b2b' ? 'b2b' : (draft ? 'b2c' : 'b2b');
            var typeB2b = h('input', { type: 'radio', name: 'ss-ed-type', value: 'b2b', checked: type === 'b2b', id: 'ss-ed-type-b2b' });
            var typeB2c = h('input', { type: 'radio', name: 'ss-ed-type', value: 'b2c', checked: type === 'b2c', id: 'ss-ed-type-b2c' });
            var email = text('email', c.email, { type: 'email', size: '30', required: true });
            var cf = {
                company: text('company', c.company, { size: '30' }), siren: text('siren', c.siren, { size: '14', inputmode: 'numeric' }),
                vat: text('vat', c.vat, { size: '16' }), firstname: text('firstname', c.firstname), lastname: text('lastname', c.lastname),
                tel: text('tel', c.tel, { type: 'tel' }), street: text('street', c.street, { size: '34' }),
                'address-complement': text('address-complement', c['address-complement'], { size: '34' }),
                'postal-code': text('postal-code', c['postal-code'], { size: '8' }), city: text('city', c.city), country: text('country', c.country || 'FR', { size: '4', maxlength: '2' })
            };
            var orgBox = h('div', { class: 'ss-bar' }, field('Raison sociale', cf.company), field('SIREN', cf.siren), field('N° de TVA', cf.vat));
            var basis = h('select', { id: 'ss-ed-basis' }, h('option', { value: 'ht' }, 'HT'), h('option', { value: 'ttc' }, 'TTC'));
            function syncType() {
                var b2b = typeB2b.checked;
                orgBox.hidden = !b2b;
                if (!draft || !d.price_basis) { basis.value = b2b ? 'ht' : 'ttc'; }
                priceLabels();
            }
            var lookupBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                if (!email.value) { return; }
                lookupBtn.disabled = true;
                api('GET', '/customers/lookup?email=' + encodeURIComponent(email.value)).then(function (res) {
                    var k = res.data.customer;
                    if (!k) { self.notify('Client inconnu : saisissez ses coordonnées.', false); return; }
                    Object.keys(cf).forEach(function (key) { if (k[key] !== undefined && k[key] !== null) { cf[key].value = k[key]; } });
                    (k.type === 'b2b' ? typeB2b : typeB2c).checked = true;
                    syncType();
                    self.notify('Coordonnées pré-remplies d’après ' + (k.company ? 'la dernière commande' : 'le compte client') + ' : vérifiez-les.', false);
                }).catch(function (e) { self.fail(e); }).then(function () { lookupBtn.disabled = false; });
            } }, 'Rechercher le client');

            // --- Lignes
            var taxTypes = inv.tax_types || {};
            var linesBody = h('tbody');
            function priceLabels() {
                self.detail.querySelectorAll('[data-price-label]').forEach(function (el) { el.textContent = 'Prix unitaire ' + basis.value.toUpperCase(); });
            }
            function addLine(l) {
                l = l || {};
                var tax = h('select', { 'aria-label': 'Type de taxe' }, Object.keys(taxTypes).map(function (k) {
                    return h('option', { value: k, selected: (l.tax_type || inv.default_tax_type) === k || l.tax_type === taxTypes[k] }, taxTypes[k]);
                }));
                var row = h('tr', {},
                    h('td', {}, h('input', { type: 'text', value: l.title || '', 'aria-label': 'Intitulé', style: 'width:100%;min-width:18rem', 'data-l': 'title' })),
                    h('td', {}, h('input', { type: 'number', min: '1', step: '1', value: l.quantity || 1, 'aria-label': 'Quantité', style: 'width:5rem', 'data-l': 'quantity' })),
                    h('td', {}, h('input', { type: 'text', inputmode: 'decimal', value: l.unit_price || '', 'aria-label': 'Prix unitaire', size: '10', placeholder: '0.00', 'data-l': 'unit_price' })),
                    h('td', {}, inv.small_business ? h('span', { class: 'ss-muted' }, 'Franchise en base (TVA non applicable)') : tax),
                    h('td', {}, h('button', { class: 'ss-btn', type: 'button', 'aria-label': 'Retirer la ligne', onclick: function () { row.remove(); } }, '×')));
                row._tax = tax;
                linesBody.appendChild(row);
            }
            (d.lines && d.lines.length ? d.lines : [{}]).forEach(addLine);

            // --- Conditions
            var nature = h('select', { id: 'ss-ed-nature' }, Object.keys(inv.natures || {}).map(function (k) {
                return h('option', { value: k, selected: (d.operation_nature || inv.operation_nature) === k }, inv.natures[k]);
            }));
            var dueDays = h('input', { type: 'number', id: 'ss-ed-due', min: '0', max: '365', value: d.due_days !== undefined ? d.due_days : inv.due_days, style: 'width:6rem' });
            var dueDate = h('input', { type: 'date', id: 'ss-ed-due-date', value: d.due_date || '' });
            var methods = inv.methods || {};
            var chosen = d.methods || Object.keys(methods);
            var methodBoxes = Object.keys(methods).map(function (k) {
                return h('label', { class: 'ss-field', style: 'flex-direction:row;align-items:center;gap:.3rem' },
                    h('input', { type: 'checkbox', value: k, checked: chosen.indexOf(k) >= 0, 'data-m': '1' }), methods[k]);
            });
            var comment = h('textarea', { id: 'ss-ed-comment', rows: '4', style: 'width:100%' });
            comment.value = d.comment || '';

            var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'submit' }, reference ? 'Enregistrer le brouillon' : 'Créer le brouillon');
            var form = h('form', { onsubmit: function (e) {
                e.preventDefault();
                var customer = { type: typeB2b.checked ? 'b2b' : 'b2c', email: email.value };
                Object.keys(cf).forEach(function (k) { customer[k] = cf[k].value; });
                var lines = Array.prototype.map.call(linesBody.children, function (row) {
                    var get = function (k) { return row.querySelector('[data-l="' + k + '"]').value; };
                    return { title: get('title'), quantity: get('quantity'), unit_price: get('unit_price'), tax_type: row._tax.value };
                });
                var body = {
                    customer: customer, price_basis: basis.value, lines: lines, operation_nature: nature.value,
                    due_days: dueDays.value, due_date: dueDate.value, comment: comment.value,
                    methods: Array.prototype.filter.call(form.querySelectorAll('[data-m]'), function (b) { return b.checked; }).map(function (b) { return b.value; })
                };
                saveBtn.disabled = true;
                (reference ? api('PUT', '/manual-orders/' + encodeURIComponent(reference), body) : api('POST', '/manual-orders', body))
                    .then(function (res) {
                        self.notify('Brouillon ' + res.data.order_reference + ' enregistré. Vérifiez l’aperçu, puis émettez le document.', false);
                        self.reloadList();
                        self.openOrder(res.data.order_reference);
                    })
                    .catch(function (err) { self.fail(err); })
                    .then(function () { saveBtn.disabled = false; });
            } },
                h('fieldset', { class: 'ss-constraint' }, h('legend', {}, 'Client'),
                    h('div', { class: 'ss-bar' }, field('E-mail (envoi du document)', email), lookupBtn),
                    h('div', { class: 'ss-bar', role: 'radiogroup', 'aria-label': 'Type de client' },
                        h('label', { for: 'ss-ed-type-b2b', class: 'ss-field', style: 'flex-direction:row;align-items:center;gap:.3rem' }, typeB2b, 'Organisation (prix HT)'),
                        h('label', { for: 'ss-ed-type-b2c', class: 'ss-field', style: 'flex-direction:row;align-items:center;gap:.3rem' }, typeB2c, 'Particulier (prix TTC)')),
                    orgBox,
                    h('div', { class: 'ss-bar' }, field('Prénom', cf.firstname), field('Nom', cf.lastname), field('Téléphone', cf.tel)),
                    h('div', { class: 'ss-bar' }, field('Adresse de facturation', cf.street), field('Complément', cf['address-complement'])),
                    h('div', { class: 'ss-bar' }, field('Code postal', cf['postal-code']), field('Ville', cf.city), field('Pays (ISO)', cf.country))),
                h('fieldset', { class: 'ss-constraint' }, h('legend', {}, 'Lignes'),
                    h('div', { class: 'ss-bar' }, field('Prix saisis', basis)),
                    h('div', { class: 'ss-table-scroll' }, h('table', {},
                        h('thead', {}, h('tr', {}, h('th', {}, 'Intitulé'), h('th', {}, 'Qté'), h('th', { 'data-price-label': '1' }, 'Prix unitaire'), h('th', {}, 'Type de taxe'), h('th', {}))),
                        linesBody)),
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () { addLine({}); priceLabels(); } }, 'Ajouter une ligne')),
                h('fieldset', { class: 'ss-constraint' }, h('legend', {}, 'Conditions'),
                    h('div', { class: 'ss-bar' }, field('Nature de l’opération', nature), field('Échéance (jours)', dueDays), field('ou date d’échéance', dueDate)),
                    h('div', { class: 'ss-bar', role: 'group', 'aria-label': 'Moyens de paiement proposés' }, h('span', { class: 'ss-muted' }, 'Moyens de paiement proposés :'), methodBoxes.length ? methodBoxes : h('span', { class: 'ss-muted' }, 'aucun (activez le virement ou une passerelle)')),
                    field('Commentaire joint à l’e-mail du client', comment)),
                h('div', { class: 'ss-bar' }, saveBtn,
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () { self.detail.innerHTML = ''; } }, 'Annuler')));

            typeB2b.addEventListener('change', syncType);
            typeB2c.addEventListener('change', syncType);
            basis.addEventListener('change', priceLabels);
            this.detail.innerHTML = '';
            this.detail.appendChild(h('div', { class: 'ss-card' }, h('h3', {}, reference ? 'Brouillon ' + reference : 'Nouvelle commande manuelle'), form));
            if (d.price_basis) { basis.value = d.price_basis; }
            syncType();
            if (d.price_basis) { basis.value = d.price_basis; priceLabels(); }
            this.detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        /** Documents d'une commande manuelle : liste, actions (PDF, copie acquittée, renvoi, transmission), émission. */
        documentsSection(o) {
            var self = this;
            var docs = o.documents || [];
            var ref = encodeURIComponent(o.order_reference);
            function pdf(d, copy) {
                return api('GET', '/documents/' + d.id + '/pdf?copy=' + copy, undefined, { blob: true })
                    .then(function (blob) { download(blob, d.number + (copy === 'paid' ? '-acquittee' : '') + '.pdf'); })
                    .catch(function (e) { self.fail(e); });
            }
            var rows = docs.map(function (d) {
                var opts = d.ereporting_options || {};
                var sel = Object.keys(opts).length ? h('select', { 'aria-label': 'Transmission de ' + d.number }, Object.keys(opts).map(function (k) {
                    return h('option', { value: k, selected: k === d.ereporting_status }, opts[k]);
                })) : null;
                if (sel) {
                    sel.addEventListener('change', function () {
                        api('PATCH', '/documents/' + d.id + '/ereporting', { status: sel.value })
                            .then(function (res) { self.notify(d.number + ' : ' + res.data.ereporting_label + '.', false); })
                            .catch(function (e) { self.fail(e); });
                    });
                }
                var link = d.public_url ? h('button', { class: 'ss-btn', type: 'button', title: d.public_url, onclick: function () {
                    if (navigator.clipboard) { navigator.clipboard.writeText(d.public_url).then(function () { self.notify('Lien de paiement copié.', false); }); }
                } }, 'Copier le lien') : null;
                return h('tr', {},
                    h('td', {}, h('strong', {}, d.number), h('div', { class: 'ss-muted' }, d.kind_label + (d.source_number ? ' (règlement de ' + d.source_number + ')' : ''))),
                    h('td', {}, dateTime(d.issued_at), d.due_date ? h('div', { class: 'ss-muted' + (d.overdue ? ' ss-neg' : '') }, 'échéance ' + new Date(d.due_date + 'T12:00:00Z').toLocaleDateString('fr-FR')) : null),
                    h('td', { class: 'ss-num' }, cents(d.total_cents, d.settlement.currency),
                        d.due_cents !== null && d.due_cents !== d.total_cents ? h('div', { class: 'ss-muted' }, 'net à payer ' + cents(d.due_cents, d.settlement.currency)) : null),
                    h('td', {}, settlementBadge(d.settlement)),
                    h('td', {}, sel || h('span', { class: 'ss-muted' }, d.ereporting_label)),
                    h('td', {}, h('div', { class: 'ss-bar', style: 'margin:0' },
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () { pdf(d, 'original'); } }, 'PDF'),
                        d.settlement.state === 'paid' && d.kind !== 'payment_request' ? h('button', { class: 'ss-btn', type: 'button', onclick: function () { pdf(d, 'paid'); } }, 'Acquittée') : null,
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                            api('POST', '/documents/' + d.id + '/send', {}).then(function () { self.notify(d.number + ' envoyé au client.', false); }).catch(function (e) { self.fail(e); });
                        } }, 'Renvoyer'),
                        link)));
            });

            var hasInvoice = docs.some(function (d) { return d.kind === 'invoice'; });
            var issueBox = null;
            if (!hasInvoice) {
                var kind = h('select', { id: 'ss-is-kind' },
                    h('option', { value: 'invoice' }, 'Facture (à régler)'),
                    h('option', { value: 'deposit' }, 'Facture d’acompte'),
                    h('option', { value: 'payment_request' }, 'Demande de paiement (facture émise au règlement)'));
                var depMode = h('select', { id: 'ss-is-dep-mode' }, h('option', { value: 'percent' }, '%'), h('option', { value: 'amount' }, 'montant'));
                var depValue = h('input', { type: 'text', id: 'ss-is-dep', size: '8', value: '30', inputmode: 'decimal' });
                var depBox = h('div', { class: 'ss-bar', hidden: true }, h('label', { class: 'ss-field', for: 'ss-is-dep' }, 'Acompte', depValue), h('label', { class: 'ss-field', for: 'ss-is-dep-mode' }, 'en', depMode));
                kind.addEventListener('change', function () { depBox.hidden = kind.value !== 'deposit'; });
                var send = h('input', { type: 'checkbox', id: 'ss-is-send', checked: true });
                var issueBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                    if (!window.confirm('Émettre le document ? Un document émis ne se modifie plus (numéro définitif).')) { return; }
                    issueBtn.disabled = true;
                    api('POST', '/orders/' + ref + '/documents', { kind: kind.value, deposit: { mode: depMode.value, value: depValue.value }, send: send.checked })
                        .then(function (res) {
                            self.notify(res.data.document.kind_label + ' ' + res.data.document.number + ' émise'
                                + (res.data.sent === true ? ' et envoyée au client.' : (res.data.sent === false ? ' — e-mail NON envoyé (voir grav.log).' : '.')), res.data.sent === false);
                            self.reloadList();
                            self.openOrder(o.order_reference);
                        })
                        .catch(function (e) { self.fail(e); })
                        .then(function () { issueBtn.disabled = false; });
                } }, 'Émettre');
                var previewBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    api('GET', '/manual-orders/' + ref + '/preview?kind=' + kind.value, undefined, { blob: true })
                        .then(function (blob) { download(blob, 'apercu-' + o.order_reference + '.pdf'); }).catch(function (e) { self.fail(e); });
                } }, 'Aperçu (PDF)');
                issueBox = h('div', { class: 'ss-section' }, h('h3', {}, 'Émettre un document'),
                    h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-is-kind' }, 'Type', kind), depBox,
                        h('label', { class: 'ss-field', for: 'ss-is-send', style: 'flex-direction:row;align-items:center;gap:.3rem' }, send, 'Envoyer au client par e-mail (PDF, commentaire, lien de paiement)')),
                    h('div', { class: 'ss-bar' }, previewBtn, issueBtn),
                    docs.length === 0 ? h('button', { class: 'ss-btn', type: 'button', onclick: function () { self.openEditor(o.order_reference, o.draft); } }, 'Modifier le brouillon') : null);
            }

            return h('section', { class: 'ss-section', 'aria-labelledby': 'ss-docs-title' },
                h('h3', { id: 'ss-docs-title' }, 'Documents'),
                rows.length ? h('div', { class: 'ss-table-scroll' }, h('table', {}, h('thead', {}, h('tr', {}, ['Document', 'Émis le', 'Montant', 'Règlement', 'Transmission', ''].map(function (c, i) {
                    return h('th', { class: i === 2 ? 'ss-num' : '' }, c);
                }))), h('tbody', {}, rows))) : h('p', { class: 'ss-muted' }, 'Aucun document émis : brouillon.'),
                issueBox);
        }

        // ---------------- Catalogue (édition rapide des fiches produit) ----------------

        showCatalog() {
            var self = this;
            if (!this.catalogType && this.catalogTypes.length) { this.catalogType = this.catalogTypes[0].name; }
            if (this.catalogTypes.length > 1) {
                var sel = h('select', { id: 'ss-cat-type' }, this.catalogTypes.map(function (t) {
                    return h('option', { value: t.name, selected: t.name === self.catalogType }, t.label);
                }));
                sel.addEventListener('change', function () { self.catalogType = sel.value; self.loadCatalog(); });
                this.body.appendChild(h('div', { class: 'ss-bar' }, h('label', { class: 'ss-field', for: 'ss-cat-type' }, 'Type de produit', sel)));
            }
            this.list = h('div');
            this.body.appendChild(this.list);
            this.loadCatalog();
        }

        loadCatalog() {
            var self = this;
            this.list.innerHTML = '';
            this.list.appendChild(h('p', { class: 'ss-muted' }, 'Chargement…'));
            api('GET', '/catalog?type=' + encodeURIComponent(this.catalogType)).then(function (res) {
                self.catalog = res.data;
                self.drawCatalog();
            }).catch(function (e) { self.list.innerHTML = ''; self.fail(e); });
        }

        /**
         * Le tableau est un vrai formulaire (POST /catalog) : chaque champ s'appelle items[i][values][champ] et sa valeur affichée
         * au chargement voyage dans items[i][original][champ] ; le serveur ne réécrit que les fiches dont une valeur a changé.
         * Le JavaScript intercepte l'envoi pour n'envoyer que les fiches modifiées (requête plus légère, même format).
         */
        drawCatalog() {
            var self = this;
            var c = this.catalog;
            this.catalogRows = [];
            this.list.innerHTML = '';
            (c.errors || []).forEach(function (err) { self.list.appendChild(h('p', { class: 'ss-ferr' }, 'Blueprint : ' + err)); });
            if (!c.rows.length) {
                this.list.appendChild(h('p', { class: 'ss-muted' }, 'Aucune fiche de ce type.'));
                return;
            }
            var basisLabel = function (b) { return b === 'ttc' ? 'TTC' : 'HT'; };
            this.catalogSubmit = h('button', { class: 'ss-btn ss-primary', type: 'submit', disabled: true }, 'Enregistrer');
            var form = h('form', { method: 'post', action: apiUrl('/catalog'), class: 'ss-cat-form', novalidate: true });
            form.appendChild(h('input', { type: 'hidden', name: 'type', value: c.type }));
            var exportBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                exportBtn.disabled = true;
                api('GET', '/catalog/export?type=' + encodeURIComponent(c.type), undefined, { blob: true })
                    .then(function (blob) { download(blob, 'catalogue-' + c.type + '-' + new Date().toISOString().slice(0, 10) + '.csv'); })
                    .catch(function (e) { self.fail(e); })
                    .then(function () { exportBtn.disabled = false; });
            } }, 'Exporter en CSV');
            form.appendChild(h('div', { class: 'ss-bar', style: 'justify-content:space-between;align-items:center' },
                h('span', { class: 'ss-muted' }, 'Champs et contraintes repris du blueprint du type de produit. Une ligne modifiée est surlignée.'),
                h('span', { class: 'ss-bar', style: 'margin:0' }, exportBtn, this.catalogSubmit)));
            var head = h('tr', {}, c.columns.map(function (col) {
                return h('th', {}, col.fields.map(function (f) {
                    return f.label + (f.price_basis ? ' (' + basisLabel(f.price_basis) + ')' : '');
                }).join(' / '));
            }));
            var body = h('tbody');
            c.rows.forEach(function (row, ri) {
                var state = { row: row, index: ri, original: Object.assign({}, row.values), inputs: {}, errs: {}, origInputs: {} };
                self.catalogRows.push(state);
                var tr = h('tr', {});
                state.tr = tr;
                c.columns.forEach(function (col, ci) {
                    var stacked = col.fields.length > 1;
                    tr.appendChild(h('td', {}, h('div', { class: 'ss-stack' }, col.fields.map(function (f) {
                        var input = self.catalogInput(f, row.values[f.name], ri, ci === 0);
                        input.name = 'items[' + ri + '][values][' + f.name + ']';
                        state.inputs[f.name] = input;
                        var orig = h('input', { type: 'hidden', name: 'items[' + ri + '][original][' + f.name + ']', value: row.values[f.name] || '' });
                        state.origInputs[f.name] = orig;
                        // Case à cocher décochée : rien n'est envoyé par le navigateur, d'où la valeur 0 qui la précède.
                        var off = f.type === 'toggle' ? h('input', { type: 'hidden', name: input.name, value: '0' }) : null;
                        var onChange = function () { self.markCatalogRow(state); };
                        input.addEventListener('input', onChange);
                        input.addEventListener('change', onChange);
                        var err = h('span', { class: 'ss-ferr', id: input.id + '-err', 'aria-live': 'polite' });
                        state.errs[f.name] = err;
                        input.setAttribute('aria-describedby', err.id);
                        var own = f.price_basis && row.price_entered_as && row.price_entered_as !== f.price_basis
                            ? h('span', { class: 'ss-badge', title: 'Base de prix propre à cette fiche' }, basisLabel(row.price_entered_as)) : null;
                        return h('div', { class: 'ss-cell' },
                            stacked ? h('small', { 'aria-hidden': 'true' }, f.label) : null,
                            orig, off,
                            f.append || own ? h('span', { class: 'ss-unit' }, input, f.append ? h('span', { class: 'ss-muted' }, f.append) : null, own) : input,
                            err);
                    }))));
                });
                // Route de la fiche (champ caché dans la première cellule), affichée sous le titre.
                tr.firstChild.appendChild(h('input', { type: 'hidden', name: 'items[' + ri + '][route]', value: row.route }));
                tr.firstChild.appendChild(h('div', { class: 'ss-muted', style: 'font-size:.72rem;word-break:break-all' }, row.route + (row.published ? '' : ' — non publiée')));
                body.appendChild(tr);
            });
            form.appendChild(h('div', { class: 'ss-table-scroll', style: 'max-height:75vh;overflow:auto' },
                h('table', { class: 'ss-cat' }, h('thead', {}, head), body)));
            form.addEventListener('submit', function (ev) { ev.preventDefault(); self.saveCatalog(); });
            this.list.appendChild(form);
        }

        /** Champ d'une cellule, d'après la définition du blueprint (type, obligatoire, motif, bornes, options). */
        catalogInput(f, value, ri, wide) {
            var id = 'ss-cat-' + ri + '-' + f.name;
            var el;
            if (f.type === 'select') {
                el = h('select', { id: id }, f.options.map(function (o) { return h('option', { value: o.value, selected: o.value === value }, o.label); }));
                if (value && !f.options.some(function (o) { return o.value === value; })) {
                    el.appendChild(h('option', { value: value, selected: true }, value + ' (hors liste)'));
                }
            } else if (f.type === 'toggle') {
                el = h('input', { id: id, type: 'checkbox', value: '1', checked: value === '1' });
            } else if (f.type === 'textarea') {
                el = h('textarea', { id: id, rows: '2', style: 'min-width:14rem' });
                el.value = value || '';
            } else {
                var type = { number: 'number', date: 'date', email: 'email', url: 'url' }[f.type] || 'text';
                var narrow = f.inputmode === 'numeric' || f.inputmode === 'decimal' || type === 'number';
                el = h('input', { id: id, type: type, value: value || '', inputmode: f.inputmode, pattern: f.pattern, placeholder: f.placeholder,
                    min: f.min, max: f.max, step: f.step, style: narrow ? 'width:6.5rem' : (type === 'date' ? '' : (wide ? 'width:20rem' : 'width:10rem')) });
            }
            if (f.required) { el.required = true; }
            el.setAttribute('aria-label', f.label);
            el.setAttribute('data-field', f.name);
            return el;
        }

        catalogValue(input) {
            return input.type === 'checkbox' ? (input.checked ? '1' : '0') : String(input.value).trim();
        }

        catalogChanges(state) {
            var self = this;
            var changes = {};
            Object.keys(state.inputs).forEach(function (name) {
                var v = self.catalogValue(state.inputs[name]);
                if (v !== String(state.original[name] || '').trim()) { changes[name] = v; }   // comme le serveur : espaces de bord ignorés
            });
            return changes;
        }

        markCatalogRow(state) {
            state.tr.classList.toggle('ss-dirty', Object.keys(this.catalogChanges(state)).length > 0);
            if (this.catalogSubmit) {
                this.catalogSubmit.disabled = !this.catalogRows.some(function (s) { return s.tr.classList.contains('ss-dirty'); });
            }
        }

        /** Envoi optimisé : seules les fiches modifiées, et pour chacune ses champs modifiés avec leur valeur d'origine. */
        saveCatalog() {
            var self = this;
            var dirty = this.catalogRows.filter(function (s) { return s.tr.classList.contains('ss-dirty'); });
            var invalid = [];
            var items = [];
            dirty.forEach(function (st) {
                Object.keys(st.errs).forEach(function (n) { st.errs[n].textContent = ''; st.inputs[n].removeAttribute('aria-invalid'); });
                var changes = self.catalogChanges(st);
                var original = {};
                Object.keys(changes).forEach(function (n) {
                    original[n] = st.original[n] || '';
                    var input = st.inputs[n];
                    if (input.checkValidity && !input.checkValidity()) {
                        input.setAttribute('aria-invalid', 'true');
                        st.errs[n].textContent = input.validationMessage || 'Valeur invalide.';
                        invalid.push(input);
                    }
                });
                items.push({ route: st.row.route, values: changes, original: original });
            });
            if (invalid.length) {
                invalid[0].focus();
                this.notify('Corrigez les champs signalés avant d’enregistrer.', true);
                return;
            }
            if (!items.length) { return; }
            this.catalogSubmit.disabled = true;
            api('POST', '/catalog', { type: this.catalog.type, items: items }).then(function (res) {
                var r = res.data;
                dirty.forEach(function (st) {
                    var out = r.items[st.row.route];
                    if (!out) { return; }
                    if (out.ok) {
                        Object.keys(out.values).forEach(function (n) {
                            st.original[n] = out.values[n];
                            if (st.origInputs[n]) { st.origInputs[n].value = out.values[n]; }
                            if (st.inputs[n] && st.inputs[n].type !== 'checkbox') { st.inputs[n].value = out.values[n]; }
                        });
                        return;
                    }
                    Object.keys(out.errors || {}).forEach(function (n) {
                        if (st.errs[n]) { st.errs[n].textContent = out.errors[n].join(' '); st.inputs[n].setAttribute('aria-invalid', 'true'); }
                    });
                    Object.keys(out.conflicts || {}).forEach(function (n) {
                        // La fiche a changé depuis l'affichage (commande déstockée, autre édition) : valeur actuelle montrée ;
                        // un nouvel enregistrement la remplacera en connaissance de cause.
                        st.original[n] = out.conflicts[n];
                        if (st.origInputs[n]) { st.origInputs[n].value = out.conflicts[n]; }
                        st.errs[n].textContent = 'Modifié entre-temps : valeur actuelle « ' + out.conflicts[n] + ' ». Enregistrez à nouveau pour la remplacer.';
                        st.inputs[n].setAttribute('aria-invalid', 'true');
                    });
                });
                dirty.forEach(function (st) { self.markCatalogRow(st); });
                self.notify(r.saved + ' fiche(s) enregistrée(s)' + (r.failed ? ', ' + r.failed + ' non enregistrée(s) : voir les champs signalés.' : '.'), r.failed > 0);
            }).catch(function (e) {
                dirty.forEach(function (st) { self.markCatalogRow(st); });
                self.fail(e);
            });
        }

        // ---------------- Transporteurs ----------------

        showCarriers() {
            var self = this;
            var data = null;
            var modesBox = h('div');
            var providersBox = h('div');
            var infoBox = h('div');
            var simBox = h('div');
            var yamlBox = h('div');

            function section(id, title, intro) {
                return h('section', { class: 'ss-section', 'aria-labelledby': id },
                    h('h3', { id: id }, title), intro ? h('p', { class: 'ss-muted' }, intro) : null);
            }

            var sProviders = section('ss-s-providers', 'Transporteurs connectés', 'Plugins transporteur installés. Leurs identifiants (clés d’API) se saisissent dans la page du plugin.');
            var sModes = section('ss-s-modes', 'Modes de livraison', 'Pour chaque mode, choisissez un forfait, le tarif du transporteur ou votre propre grille. Les prix sont saisis hors taxe : la TVA est ajoutée automatiquement, puis les contraintes globales.');
            var sCons = section('ss-s-cons', 'Contraintes globales', 'Lois et offres qui s’imposent à tous les modes (sauf ceux exclus), dans l’ordre de la liste, après le calcul du mode (TVA comprise). Dans une contrainte, la première règle qui correspond s’applique ; bornes incluses, case vide = sans limite. Le commentaire explique la règle ; il apparaît dans le simulateur.');
            var consBox = h('div');
            var consStatus = h('p', { class: 'ss-done', role: 'status', tabindex: '-1' });
            sCons.appendChild(consBox);
            var sSim = section('ss-s-sim', 'Simulateur', 'Vérifiez le prix que verrait un client pour un colis donné.');
            var sYaml = h('details', { class: 'ss-advanced' }, h('summary', {}, 'Mode avancé : fichier YAML'));

            var modesStatus = h('p', { class: 'ss-done', role: 'status', tabindex: '-1' });
            var editors = {};
            var simRefresh = null;
            var draftHolder = h('div');

            sProviders.appendChild(providersBox);
            sModes.appendChild(modesStatus);
            sModes.appendChild(modesBox);
            sSim.appendChild(simBox);
            sYaml.appendChild(yamlBox);

            this.body.appendChild(infoBox);
            this.body.appendChild(sProviders);
            this.body.appendChild(sModes);
            this.body.appendChild(sCons);
            this.body.appendChild(sSim);
            this.body.appendChild(sYaml);

            /**
             * Recharge les données. after = { key, message } : rouvre le mode « key »,
             * y affiche le message et y place le focus ; sans « key » (mode supprimé),
             * le message s'affiche en tête de la liste des modes, qui reçoit le focus.
             * Le simulateur garde sa saisie et recalcule s'il avait déjà un résultat.
             */
            function reload(after) {
                return api('GET', '/carriers').then(function (res) {
                    data = res.data;
                    drawInfo();
                    drawProviders();
                    drawModes();
                    drawConstraints();
                    if (simRefresh) { simRefresh(); } else { drawSimulator(); }
                    drawYaml();
                    if (after && after.constraints) {
                        consStatus.className = 'ss-done';
                        consStatus.textContent = after.constraints;
                        consStatus.focus();
                    }
                    if (after && after.message) {
                        var ed = after.key ? editors[after.key] : null;
                        if (ed) {
                            ed.done(after.message);
                        } else {
                            modesStatus.textContent = after.message;
                            modesStatus.focus();
                        }
                    }
                }).catch(function (e) { self.fail(e); });
            }

            // ----- Rappel : TVA et emballage -----
            function drawInfo() {
                var pct = ((data.tax || {}).vat_rate || 0) * 100;
                var pack = (data.settings || {}).packaging || {};
                infoBox.innerHTML = '';
                infoBox.appendChild(h('p', { class: 'ss-muted' }, 'Prix saisis ' + (isHt() ? 'hors taxe, TVA ' + pct.toLocaleString('fr-FR') + ' % ajoutée automatiquement' : 'toutes taxes comprises')
                    + '. Emballage ajouté à chaque colis : ' + pack.weight + ' g et ' + pack.thickness + ' mm (Plugins > Simple Shop > Livraison).'));
            }

            // ----- Fournisseurs -----
            function drawProviders() {
                providersBox.innerHTML = '';
                if (!data.providers.length) {
                    providersBox.appendChild(h('p', { class: 'ss-muted' }, 'Aucun plugin transporteur actif. Seuls les modes manuels ci-dessous sont proposés.'));
                    return;
                }
                data.providers.forEach(function (p) {
                    var status = h('span', { role: 'status', class: 'ss-muted' });
                    var btn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        btn.disabled = true;
                        status.textContent = 'Test en cours…';
                        api('POST', '/carriers/providers/' + encodeURIComponent(p.id) + '/test', {})
                            .then(function (res) { status.textContent = (res.data.ok ? 'Réussi : ' : 'Échec : ') + res.data.message; status.className = res.data.ok ? 'ss-ok' : 'ss-ko'; })
                            .catch(function (e) { status.textContent = 'Échec : ' + e.message; status.className = 'ss-ko'; })
                            .then(function () { btn.disabled = false; });
                    } }, 'Tester la connexion');
                    providersBox.appendChild(h('div', { class: 'ss-provider' },
                        h('strong', {}, p.label), ' ', h('span', { class: 'ss-muted' }, '(plugin « ' + p.id + ' »)'), ' ', btn, ' ', status));
                });
            }

            // ----- Modes -----
            function drawModes() {
                modesBox.innerHTML = '';
                modesStatus.textContent = '';
                editors = {};
                draftHolder.innerHTML = '';
                data.modes.forEach(function (m) {
                    var ed = modeEditor(m, false);
                    editors[m.key] = ed;
                    modesBox.appendChild(ed.el);
                });
                modesBox.appendChild(draftHolder);
                modesBox.appendChild(newModeForm());
            }

            function sourceLabel(m) {
                if (m.source === 'manual') { return 'Manuel'; }
                var label = 'Plugin ' + m.provider;
                return m.source === 'provider+override' ? label + ' — modifié' : label;
            }

            function statusLabel(m) {
                if (!m.available) { return 'Indisponible : ' + (m.unavailable_reason || 'réglages du plugin incomplets'); }
                return m.enabled ? 'Actif' : 'Désactivé';
            }

            function field(label, input, help) {
                var id = input.id;
                return h('div', { class: 'ss-field-row' },
                    h('label', { for: id }, label), input,
                    help ? h('small', { id: id + '-help', class: 'ss-muted' }, help) : null);
            }

            var uid = 0;
            function nextId(prefix) { uid++; return prefix + '-' + uid; }

            function numInput(value, opts) {
                opts = opts || {};
                return h('input', {
                    type: 'number', id: nextId('ss-n'), min: '0', step: opts.step || 'any', inputmode: 'decimal',
                    value: value === null || value === undefined ? '' : String(value), placeholder: opts.placeholder || '',
                    'aria-label': opts.aria || null, 'aria-describedby': opts.describedby || null
                });
            }

            var PRICING_LABELS = { flat: 'Forfait', provider: 'Tarif du plugin', grid: 'Grille personnalisée' };
            var CSV_COLUMNS = ['zone', 'poids_de_g', 'poids_jusqua_g', 'panier_de_eur', 'panier_jusqua_eur', 'epaisseur_max_mm', 'prix_eur'];
            var WEIGHT_INFINITE = 9999999, BASKET_INFINITE = 99999;

            function isHt() { return (data.settings || {}).prices_entered === 'ht'; }
            function taxLabel() { return isHt() ? 'HT' : 'TTC'; }

            /** « 33 lignes, de 6,72 € à 36,39 € HT, jusqu'à 30 000 g » */
            function gridSummary(rules) {
                if (!rules.length) { return 'Grille vide.'; }
                var prices = rules.map(function (r) { return parseFloat(r.price); }).filter(function (p) { return !isNaN(p); });
                var maxW = 0, unlimited = false;
                rules.forEach(function (r) {
                    var w = r.max_weight === null || r.max_weight === '' || r.max_weight === undefined ? Infinity : parseFloat(r.max_weight);
                    if (w >= WEIGHT_INFINITE) { unlimited = true; } else if (w > maxW) { maxW = w; }
                });
                var text = rules.length + (rules.length > 1 ? ' lignes' : ' ligne');
                if (prices.length) {
                    var lo = Math.min.apply(null, prices), hi = Math.max.apply(null, prices);
                    text += lo === hi ? ', ' + money(lo) + ' ' + taxLabel() : ', de ' + money(lo) + ' à ' + money(hi) + ' ' + taxLabel();
                }
                text += unlimited ? ', sans limite de poids' : (maxW ? ', jusqu’à ' + maxW.toLocaleString('fr-FR') + ' g' : '');

                return text + '.';
            }

            function usesAdvancedColumns(rules) {
                return rules.some(function (r) {
                    return (parseFloat(r.min_basket) || 0) > 0
                        || (r.max_basket !== null && r.max_basket !== undefined && r.max_basket !== '' && parseFloat(r.max_basket) < BASKET_INFINITE)
                        || (r.max_thickness !== null && r.max_thickness !== undefined && r.max_thickness !== '' && parseFloat(r.max_thickness) > 0);
                });
            }

            /** Texte CSV (séparateur « ; », virgule décimale, en-tête) d'une liste de lignes de grille. */
            function rulesToCsv(rules) {
                function num(v, infinite) {
                    if (v === null || v === undefined || v === '' || (infinite && parseFloat(v) >= infinite)) { return ''; }
                    return String(v).replace('.', ',');
                }
                var lines = [CSV_COLUMNS.slice(0, 6).concat(['prix_eur_' + taxLabel().toLowerCase()]).join(';')];
                rules.forEach(function (r) {
                    lines.push([r.zone, num(r.min_weight), num(r.max_weight, WEIGHT_INFINITE), num(r.min_basket), num(r.max_basket, BASKET_INFINITE),
                        num(r.max_thickness), num(r.price)].join(';'));
                });

                return '﻿' + lines.join('\r\n') + '\r\n';
            }

            /**
             * Lit un CSV de grille (colonnes dans l'ordre de l'export ; séparateur « ; », « , » ou tabulation ;
             * virgule ou point décimal ; ligne d'en-tête facultative).
             * @return {{rules: Array, errors: string[]}}
             */
            function csvToRules(text) {
                var lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
                var errors = [], rules = [];
                if (!lines.length) { return { rules: [], errors: ['Le fichier est vide.'] }; }
                var first = lines[0];
                var delim = first.indexOf(';') > -1 ? ';' : (first.indexOf('\t') > -1 ? '\t' : ',');
                lines.forEach(function (line, i) {
                    var cells = line.split(delim).map(function (c) { return c.trim().replace(/^"|"$/g, '').trim(); });
                    if (i === 0 && data.all_zones.indexOf(cells[0]) === -1) { return; } // en-tête
                    var n = i + 1;
                    if (data.all_zones.indexOf(cells[0]) === -1) { errors.push('Ligne ' + n + ' : zone « ' + cells[0] + ' » inconnue (' + data.all_zones.join(', ') + ').'); return; }
                    function num(k, label, required) {
                        var raw = (cells[k] || '').replace(/\s/g, '').replace(',', '.');
                        if (raw === '') {
                            if (required) { errors.push('Ligne ' + n + ' : ' + label + ' manquant.'); }
                            return null;
                        }
                        if (isNaN(Number(raw)) || Number(raw) < 0) { errors.push('Ligne ' + n + ' : ' + label + ' invalide (« ' + cells[k] + ' »).'); return null; }
                        return Number(raw);
                    }
                    rules.push({
                        zone: cells[0], min_weight: num(1, 'poids de') || 0, max_weight: num(2, 'poids jusqu’à'),
                        min_basket: num(3, 'panier de') || 0, max_basket: num(4, 'panier jusqu’à'),
                        max_thickness: num(5, 'épaisseur'), price: num(6, 'prix', true)
                    });
                });
                if (!rules.length && !errors.length) { errors.push('Aucune ligne de tarif trouvée.'); }

                return { rules: rules, errors: errors };
            }

            /** Retrait sur place : prix unique, adresse du lieu de retrait, distance maximale ; ni grille ni marge. */
            function localPickupEditor(m, isNew) {
                var uidp = nextId('ss-mode');
                var status = h('p', { class: 'ss-form-status', role: 'status', tabindex: '-1' });
                var errors = h('div', { role: 'alert' });
                var addr = m.pickup_address || {};
                var inputs = {
                    enabled: h('input', { type: 'checkbox', id: uidp + '-enabled', checked: !!m.enabled }),
                    name: h('input', { type: 'text', id: uidp + '-name', value: m.name || '', required: true, maxlength: '80' }),
                    description: h('textarea', { id: uidp + '-desc', rows: '2', maxlength: '300' }),
                    price: numInput(m.flat_price === null || m.flat_price === undefined ? 0 : m.flat_price),
                    street: h('input', { type: 'text', id: uidp + '-street', value: addr.street || '', maxlength: '120', autocomplete: 'street-address' }),
                    postal: h('input', { type: 'text', id: uidp + '-postal', value: addr.postal_code || '', maxlength: '5', inputmode: 'numeric', pattern: '[0-9]{5}', autocomplete: 'postal-code' }),
                    city: h('input', { type: 'text', id: uidp + '-city', value: addr.city || '', maxlength: '120', autocomplete: 'address-level2' }),
                    distance: numInput(m.max_distance_km)
                };
                inputs.description.value = m.description || '';
                inputs.price.id = uidp + '-price';
                inputs.distance.id = uidp + '-dist';

                var located = m.geo
                    ? h('p', { class: 'ss-muted' }, 'Lieu localisé : ' + (m.geo.label || (m.geo.lat + ', ' + m.geo.lon)) + '.')
                    : h('p', { class: 'ss-msg err' }, (addr.street ? 'Adresse non localisée : ' : 'Saisissez l’adresse : ') + 'le retrait n’est pas proposé tant que le lieu n’est pas localisé.');

                function v(i) { return i.value === '' ? null : i.value; }
                function read() {
                    return {
                        kind: 'local_pickup', name: inputs.name.value, description: inputs.description.value, enabled: inputs.enabled.checked,
                        flat_price: v(inputs.price), max_distance_km: v(inputs.distance),
                        pickup_address: { street: inputs.street.value, postal_code: inputs.postal.value, city: inputs.city.value }
                    };
                }
                function showErrors(e) {
                    errors.innerHTML = '';
                    status.textContent = '';
                    errors.appendChild(h('div', { class: 'ss-msg err' }, e.message,
                        e.details && e.details.length ? h('ul', { class: 'ss-list' }, e.details.map(function (d) { return h('li', {}, d); })) : null));
                    errors.scrollIntoView({ block: 'nearest' });
                }

                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'submit' }, isNew ? 'Créer le mode' : 'Enregistrer ce mode');
                var delBtn = isNew ? null : h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    if (!window.confirm('Supprimer le mode « ' + m.name + ' » ?')) { return; }
                    api('DELETE', '/carriers/' + encodeURIComponent(m.key))
                        .then(function () { return reload({ message: 'Mode « ' + m.name + ' » supprimé.' }); })
                        .catch(showErrors);
                } }, 'Supprimer ce mode');

                var form = h('form', { class: 'ss-mode-form', onsubmit: function (ev) {
                    ev.preventDefault();
                    errors.innerHTML = '';
                    saveBtn.disabled = true;
                    status.className = 'ss-form-status';
                    status.textContent = 'Enregistrement (localisation de l’adresse)…';
                    api('PUT', '/carriers/' + encodeURIComponent(m.key), read())
                        .then(function (res) {
                            var warning = res && res.data && res.data.warning ? ' ' + res.data.warning : '';
                            return reload({ key: m.key, message: 'Mode « ' + inputs.name.value + ' » ' + (isNew ? 'créé.' : 'enregistré.') + warning });
                        })
                        .catch(showErrors)
                        .then(function () { saveBtn.disabled = false; });
                } },
                    h('div', { class: 'ss-field-row ss-check' }, inputs.enabled, h('label', { for: uidp + '-enabled' }, 'Proposer ce mode aux clients')),
                    field('Nom affiché au client', inputs.name),
                    field('Description affichée au client', inputs.description),
                    field('Prix (€ ' + taxLabel() + ')', inputs.price, '0 = gratuit. Les contraintes globales s’appliquent ensuite, sauf si ce mode en est exclu.'),
                    h('fieldset', {}, h('legend', {}, 'Lieu de retrait'),
                        field('Rue', inputs.street),
                        h('div', { class: 'ss-inline' }, field('Code postal', inputs.postal), field('Ville', inputs.city)),
                        located),
                    field('Distance maximale du client (km)', inputs.distance, 'Le mode n’est proposé qu’aux clients dont la commune est à au plus cette distance, à vol d’oiseau.'),
                    h('p', { class: 'ss-muted' }, 'L’adresse est localisée par le service public de la Base Adresse Nationale ; pour un client, seuls son code postal et sa ville sont envoyés.'),
                    errors,
                    h('div', { class: 'ss-bar' }, saveBtn, delBtn),
                    status);

                var summary = h('summary', {},
                    h('span', { class: 'ss-mode-name' }, m.name || 'Nouveau mode'), ' ',
                    h('span', { class: 'ss-badge' }, 'Retrait sur place'), ' ',
                    isNew ? null : h('span', { class: 'ss-badge ss-b-off' }, (m.max_distance_km ? 'jusqu’à ' + Number(m.max_distance_km).toLocaleString('fr-FR') + ' km' : 'distance à régler')
                        + (m.flat_price > 0 ? ', ' + money(m.flat_price) + ' ' + taxLabel() : ', gratuit')), ' ',
                    h('span', { class: 'ss-badge ' + (!m.geo && m.enabled ? 'ss-b-ko' : (m.enabled ? 'ss-b-on' : 'ss-b-off')) }, m.enabled ? (m.geo ? 'Actif' : 'Actif, non localisé') : 'Désactivé'));
                var el = h('details', { class: 'ss-mode', open: isNew }, summary, form);

                return {
                    el: el,
                    focus: function () { inputs.name.focus(); },
                    done: function (message) {
                        el.open = true;
                        status.className = 'ss-form-status ss-done';
                        status.textContent = message;
                        status.focus();
                        status.scrollIntoView({ block: 'center' });
                    }
                };
            }

            function modeEditor(m, isNew) {
                if (m.kind === 'local_pickup') { return localPickupEditor(m, isNew); }
                var inputs = {};
                var uidp = nextId('ss-mode');
                var isProvider = !!m.provider;
                var pricing = m.pricing || (isProvider ? 'provider' : 'grid');

                var status = h('p', { class: 'ss-form-status', role: 'status', tabindex: '-1' });
                var errors = h('div', { role: 'alert' });

                inputs.enabled = h('input', { type: 'checkbox', id: uidp + '-enabled', checked: !!m.enabled });
                inputs.name = h('input', { type: 'text', id: uidp + '-name', value: m.name || '', required: true, maxlength: '80' });
                inputs.description = h('textarea', { id: uidp + '-desc', rows: '2', maxlength: '300' });
                inputs.description.value = m.description || '';
                inputs.flat = numInput(m.flat_price);
                inputs.flat.id = uidp + '-flat';
                inputs.mfixed = numInput(m.margin.fixed);
                inputs.mfixed.id = uidp + '-mfixed';
                inputs.mpercent = numInput(m.margin.percent);
                inputs.mpercent.id = uidp + '-mpercent';
                inputs.free = numInput(m.free_from);
                inputs.free.id = uidp + '-free';
                inputs.maxw = numInput(m.constraints.max_weight);
                inputs.maxw.id = uidp + '-maxw';
                inputs.maxt = numInput(m.constraints.max_thickness);
                inputs.maxt.id = uidp + '-maxt';

                // ----- Choix du calcul -----
                var choices = isProvider ? ['flat', 'provider', 'grid'] : ['flat', 'grid'];
                var hints = {
                    flat: 'Un seul prix, quel que soit le colis. Le plus simple.',
                    provider: 'La grille fournie par le plugin ' + m.provider + ', mise à jour avec le plugin. Rien à saisir.',
                    grid: 'Votre propre grille : prix selon le poids (et, si besoin, le montant du panier ou l’épaisseur).'
                };
                var radios = {};
                var pricingFieldset = h('fieldset', {}, h('legend', {}, 'Calcul du prix'),
                    choices.map(function (c) {
                        radios[c] = h('input', { type: 'radio', name: uidp + '-pricing', id: uidp + '-p-' + c, value: c, checked: c === pricing,
                            'aria-describedby': uidp + '-p-' + c + '-hint', onchange: function () { setPricing(c); } });
                        return h('div', { class: 'ss-field-row ss-check ss-choice' }, radios[c],
                            h('label', { for: uidp + '-p-' + c }, h('strong', {}, PRICING_LABELS[c]), ' ', h('span', { id: uidp + '-p-' + c + '-hint', class: 'ss-muted' }, hints[c])));
                    }));

                // ----- Forfait -----
                var flatBlock = h('div', {},
                    field('Prix du forfait (€ ' + taxLabel() + ')', inputs.flat, '0 = gratuit, avant les contraintes globales (qui peuvent imposer un minimum).'));

                // ----- Grille du plugin (lecture seule) -----
                var providerRules = pricing === 'provider' ? (m.rules || []) : null;
                var providerBlock = h('div', {});
                if (isProvider) {
                    var shownRules = providerRules || [];
                    var roTable = h('table', {},
                        h('caption', { class: 'ss-sr' }, 'Grille du plugin pour ' + (m.name || '')),
                        h('thead', {}, h('tr', {}, ['Zone', 'Poids de (g)', 'Jusqu’à (g)', 'Prix (€ ' + taxLabel() + ')'].map(function (t) { return h('th', { scope: 'col' }, t); }))),
                        h('tbody', {}, shownRules.map(function (r) {
                            return h('tr', {}, h('td', {}, r.zone), h('td', { class: 'ss-num' }, (r.min_weight || 0).toLocaleString('fr-FR')),
                                h('td', { class: 'ss-num' }, r.max_weight >= WEIGHT_INFINITE ? 'sans limite' : Number(r.max_weight).toLocaleString('fr-FR')),
                                h('td', { class: 'ss-num' }, money(r.price)));
                        })));
                    providerBlock.appendChild(h('p', {}, 'Grille du plugin : ' + gridSummary(shownRules)));
                    if (shownRules.length) {
                        providerBlock.appendChild(h('details', { class: 'ss-grid-details' }, h('summary', {}, 'Voir la grille du plugin'),
                            h('div', { class: 'ss-table-scroll' }, roTable),
                            h('div', { class: 'ss-bar' },
                                h('button', { class: 'ss-btn', type: 'button', onclick: function () { download(new Blob([rulesToCsv(shownRules)], { type: 'text/csv;charset=utf-8' }), m.key + '-grille.csv'); } }, 'Exporter en CSV'))));
                    }
                    providerBlock.appendChild(h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        radios.grid.checked = true;
                        setPricing('grid');
                        gridDetails.open = true;
                        (rows[0] ? rows[0].price : addBtn).focus();
                    } }, 'Personnaliser cette grille'));
                }

                // ----- Grille personnalisée (modifiable) -----
                var zones = (data.zones && data.zones.length) ? data.zones.slice() : data.all_zones.slice();
                var tbody = h('tbody');
                var rows = [];
                var gridSummaryText = h('p', {});

                function cell(input, advanced) { return h('td', { class: advanced ? 'ss-adv-col' : null }, input); }

                function addRow(rule) {
                    rule = rule || { zone: zones[0], min_weight: 0, max_weight: null, min_basket: 0, max_basket: null, price: '' };
                    var n = rows.length + 1;
                    var zoneList = zones.indexOf(rule.zone) === -1 ? zones.concat([rule.zone]) : zones;
                    var r = {
                        zone: h('select', { 'aria-label': 'Zone, ligne ' + n }, zoneList.map(function (z) { return h('option', { value: z, selected: z === rule.zone }, z); })),
                        minw: numInput(rule.min_weight, { aria: 'Poids de (g), ligne ' + n }),
                        maxw: numInput(rule.max_weight !== null && rule.max_weight >= WEIGHT_INFINITE ? null : rule.max_weight, { aria: 'Poids jusqu’à (g), ligne ' + n, placeholder: 'sans limite' }),
                        minb: numInput(rule.min_basket, { aria: 'Panier de (€), ligne ' + n }),
                        maxb: numInput(rule.max_basket !== null && rule.max_basket >= BASKET_INFINITE ? null : rule.max_basket, { aria: 'Panier jusqu’à (€), ligne ' + n, placeholder: 'sans limite' }),
                        thick: numInput(rule.max_thickness, { aria: 'Épaisseur maximale (mm), ligne ' + n, placeholder: 'sans limite' }),
                        price: numInput(rule.price, { aria: 'Prix (€ ' + taxLabel() + '), ligne ' + n })
                    };
                    r.del = h('button', { class: 'ss-btn', type: 'button', 'aria-label': 'Supprimer la ligne ' + n, onclick: function () {
                        rows.splice(rows.indexOf(r), 1);
                        r.tr.remove();
                        refreshGridSummary();
                        status.textContent = 'Ligne supprimée (pas encore enregistré).';
                        (rows.length ? rows[0].zone : addBtn).focus();
                    } }, 'Supprimer');
                    r.tr = h('tr', {}, cell(r.zone), cell(r.minw), cell(r.maxw), cell(r.minb, true), cell(r.maxb, true), cell(r.thick, true), cell(r.price), cell(r.del));
                    rows.push(r);
                    tbody.appendChild(r.tr);

                    return r;
                }

                function v(i) { return i.value === '' ? null : i.value; }
                function currentRules() {
                    return rows.map(function (r) {
                        return { zone: r.zone.value, min_weight: v(r.minw), max_weight: v(r.maxw), min_basket: v(r.minb), max_basket: v(r.maxb), max_thickness: v(r.thick), price: v(r.price) };
                    });
                }
                function refreshGridSummary() {
                    gridSummaryText.textContent = 'Votre grille : ' + gridSummary(currentRules());
                    gridDetailsLabel.textContent = 'Modifier la grille (' + rows.length + (rows.length > 1 ? ' lignes)' : ' ligne)');
                }

                var addBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    var r = addRow();
                    refreshGridSummary();
                    r.minw.focus();
                } }, 'Ajouter une ligne');

                (m.rules || []).forEach(addRow);

                var advToggle = h('input', { type: 'checkbox', id: uidp + '-adv', checked: usesAdvancedColumns(m.rules || []), onchange: function () {
                    table.classList.toggle('ss-hide-adv', !advToggle.checked);
                } });
                var table = h('table', { class: advToggle.checked ? null : 'ss-hide-adv' },
                    h('caption', { class: 'ss-sr' }, 'Grille de tarifs du mode ' + (m.name || 'nouveau')),
                    h('thead', {}, h('tr', {}, [['Zone'], ['Poids de (g)'], ['Jusqu’à (g)'], ['Panier de (€)', 1], ['Jusqu’à (€)', 1], ['Épaisseur max (mm)', 1], ['Prix (€ ' + taxLabel() + ')'], ['']].map(function (t) {
                        return h('th', { scope: 'col', class: t[1] ? 'ss-adv-col' : null }, t[0]);
                    }))),
                    tbody);

                var fileInput = h('input', { type: 'file', accept: '.csv,text/csv,text/plain', class: 'ss-sr', id: uidp + '-csv', tabindex: '-1', onchange: function () {
                    var file = fileInput.files && fileInput.files[0];
                    if (!file) { return; }
                    file.text().then(function (text) {
                        var parsed = csvToRules(text);
                        fileInput.value = '';
                        if (parsed.errors.length) {
                            showErrors({ message: 'Import impossible : le fichier n’a pas été pris en compte.', details: parsed.errors.slice(0, 20) });
                            return;
                        }
                        if (rows.length && !window.confirm('Remplacer les ' + rows.length + ' lignes actuelles par les ' + parsed.rules.length + ' lignes du fichier ?')) { return; }
                        rows.slice().forEach(function (r) { r.tr.remove(); });
                        rows.length = 0;
                        parsed.rules.forEach(addRow);
                        if (usesAdvancedColumns(parsed.rules) && !advToggle.checked) { advToggle.checked = true; table.classList.remove('ss-hide-adv'); }
                        refreshGridSummary();
                        errors.innerHTML = '';
                        status.className = 'ss-form-status';
                        status.textContent = parsed.rules.length + ' lignes importées. Vérifiez puis enregistrez le mode.';
                        status.focus();
                    });
                } });

                var gridDetailsLabel = h('span', {});
                var gridDetails = h('details', { class: 'ss-grid-details', open: isNew }, h('summary', {}, gridDetailsLabel),
                    h('p', { class: 'ss-muted' }, 'La première ligne qui correspond au colis s’applique. Poids en grammes ; laissez « jusqu’à » vide pour « sans limite ».'),
                    h('div', { class: 'ss-field-row ss-check' }, advToggle, h('label', { for: uidp + '-adv' }, 'Afficher les colonnes avancées (montant du panier, épaisseur)')),
                    h('div', { class: 'ss-table-scroll', role: 'region', tabindex: '0', 'aria-label': 'Grille de tarifs, défilement horizontal' }, table),
                    h('div', { class: 'ss-bar' }, addBtn,
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                            download(new Blob([rulesToCsv(currentRules())], { type: 'text/csv;charset=utf-8' }), m.key + '-grille.csv');
                        } }, 'Exporter en CSV'),
                        h('button', { class: 'ss-btn', type: 'button', onclick: function () { fileInput.click(); } }, 'Importer un CSV…'),
                        fileInput),
                    h('p', { class: 'ss-muted' }, 'CSV : une ligne par tranche, colonnes ' + CSV_COLUMNS.join(' ; ') + ' (séparateur « ; », virgule décimale ; l’export donne un modèle).'));
                var gridBlock = h('div', {}, gridSummaryText, gridDetails);
                refreshGridSummary();

                var marginFieldset = h('fieldset', {}, h('legend', {}, 'Marge sur le tarif de la grille'),
                    h('div', { class: 'ss-inline' },
                        field('Marge fixe (€ ' + taxLabel() + ')', inputs.mfixed),
                        field('Marge (%)', inputs.mpercent)),
                    h('p', { class: 'ss-muted' }, 'Ajoutées au prix de la grille (ex. 0,50 € pour le suivi de la lettre verte). Pas de marge sur une ligne à 0 €.'));

                function setPricing(p) {
                    pricing = p;
                    flatBlock.hidden = p !== 'flat';
                    providerBlock.hidden = p !== 'provider';
                    gridBlock.hidden = p !== 'grid';
                    marginFieldset.hidden = p === 'flat';
                    if (p === 'grid' && !rows.length && isProvider) {
                        (m.provider_rules || m.rules || []).forEach(addRow);
                        refreshGridSummary();
                    }
                }

                function read() {
                    return {
                        name: inputs.name.value,
                        description: inputs.description.value,
                        enabled: inputs.enabled.checked,
                        pricing: pricing,
                        flat_price: pricing === 'flat' ? v(inputs.flat) : null,
                        margin: { fixed: v(inputs.mfixed), percent: v(inputs.mpercent) },
                        free_from: v(inputs.free),
                        constraints: { max_weight: v(inputs.maxw), max_thickness: v(inputs.maxt) },
                        rules: pricing === 'grid' ? currentRules() : []
                    };
                }

                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'submit' }, isNew ? 'Créer le mode' : 'Enregistrer ce mode');
                var extraBtn = null;
                if (!isNew && m.source === 'provider+override') {
                    extraBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () { remove('Rétablir les valeurs du plugin pour « ' + m.name + ' » ? Vos modifications de ce mode seront perdues.'); } }, 'Rétablir les valeurs du plugin');
                } else if (!isNew && m.source === 'manual') {
                    extraBtn = h('button', { class: 'ss-btn', type: 'button', onclick: function () { remove('Supprimer le mode « ' + m.name + ' » ?'); } }, 'Supprimer ce mode');
                }

                function remove(question) {
                    if (!window.confirm(question)) { return; }
                    api('DELETE', '/carriers/' + encodeURIComponent(m.key))
                        .then(function () {
                            return m.source === 'manual'
                                ? reload({ message: 'Mode « ' + m.name + ' » supprimé.' })
                                : reload({ key: m.key, message: 'Mode « ' + m.name + ' » : valeurs du plugin rétablies.' });
                        })
                        .catch(function (e) { showErrors(e); });
                }

                function showErrors(e) {
                    errors.innerHTML = '';
                    status.textContent = '';
                    errors.appendChild(h('div', { class: 'ss-msg err' }, e.message,
                        e.details && e.details.length ? h('ul', { class: 'ss-list' }, e.details.map(function (d) { return h('li', {}, d); })) : null));
                    errors.scrollIntoView({ block: 'nearest' });
                }

                var form = h('form', { class: 'ss-mode-form', onsubmit: function (ev) {
                    ev.preventDefault();
                    errors.innerHTML = '';
                    saveBtn.disabled = true;
                    status.className = 'ss-form-status';
                    status.textContent = 'Enregistrement…';
                    api('PUT', '/carriers/' + encodeURIComponent(m.key), read())
                        .then(function () { return reload({ key: m.key, message: 'Mode « ' + inputs.name.value + ' » ' + (isNew ? 'créé.' : 'enregistré.') }); })
                        .catch(function (e) { showErrors(e); })
                        .then(function () { saveBtn.disabled = false; });
                } },
                    h('div', { class: 'ss-field-row ss-check' }, inputs.enabled, h('label', { for: uidp + '-enabled' }, 'Proposer ce mode aux clients')),
                    m.available === false ? h('p', { class: 'ss-msg err' }, 'Indisponible pour le moment : ' + (m.unavailable_reason || 'réglages du plugin incomplets') + ' Le mode n’est pas proposé tant que ce n’est pas réglé.') : null,
                    field('Nom affiché au client', inputs.name),
                    field('Description affichée au client', inputs.description),
                    pricingFieldset,
                    h('div', { class: 'ss-pricing-body' }, flatBlock, providerBlock, gridBlock),
                    marginFieldset,
                    h('fieldset', {}, h('legend', {}, 'Règles'),
                        h('div', { class: 'ss-inline' },
                            field('Offert dès (total TTC, €)', inputs.free, 'Vide = jamais. Au-dessus, la livraison du mode est offerte, sous réserve des contraintes globales.'),
                            field('Poids maximal du colis (g)', inputs.maxw, 'Vide = illimité.'),
                            field('Épaisseur maximale (mm)', inputs.maxt, 'Vide = illimité.')),
                        h('p', { class: 'ss-muted' }, 'Les contraintes globales (plus bas) s’appliquent ensuite ; un mode peut en être exclu.')),
                    errors,
                    h('div', { class: 'ss-bar' }, saveBtn, extraBtn),
                    status);

                setPricing(pricing);

                var pricingBadge = pricing === 'flat' && m.flat_price !== null && m.flat_price !== undefined
                    ? 'Forfait ' + money(m.flat_price) + ' ' + taxLabel()
                    : PRICING_LABELS[pricing];
                var summary = h('summary', {},
                    h('span', { class: 'ss-mode-name' }, m.name || 'Nouveau mode'), ' ',
                    h('span', { class: 'ss-badge' }, sourceLabel(m)), ' ',
                    isNew ? null : h('span', { class: 'ss-badge ss-b-off' }, pricingBadge), ' ',
                    h('span', { class: 'ss-badge ' + (!m.available ? 'ss-b-ko' : (m.enabled ? 'ss-b-on' : 'ss-b-off')) }, statusLabel(m)));
                var el = h('details', { class: 'ss-mode', open: isNew }, summary, form);

                return {
                    el: el,
                    focus: function () { inputs.name.focus(); },
                    /** Après un enregistrement : mode ouvert, message visible et focus dessus. */
                    done: function (message) {
                        el.open = true;
                        status.className = 'ss-form-status ss-done';
                        status.textContent = message;
                        status.focus();
                        status.scrollIntoView({ block: 'center' });
                    }
                };
            }

            function newModeForm() {
                var nameInput = h('input', { type: 'text', id: 'ss-new-mode-name', maxlength: '80' });
                var kindSelect = h('select', { id: 'ss-new-mode-kind' },
                    h('option', { value: 'delivery' }, 'Livraison'), h('option', { value: 'local_pickup' }, 'Retrait sur place'));
                var btn = h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                    var name = nameInput.value.trim();
                    if (!name) { nameInput.focus(); return; }
                    var base = slug(name), key = base, i = 2;
                    var existing = data.modes.map(function (x) { return x.key; });
                    while (existing.indexOf(key) !== -1) { key = base + '_' + (i++); }
                    var draft = kindSelect.value === 'local_pickup'
                        ? { key: key, source: 'manual', provider: null, kind: 'local_pickup', name: name, description: '', enabled: false,
                            flat_price: 0, max_distance_km: 20, pickup_address: { street: '', postal_code: '', city: '' }, geo: null, available: true }
                        : { key: key, source: 'manual', provider: null, name: name, description: '', enabled: false,
                        pricing: 'flat', flat_price: null, margin: { fixed: 0, percent: 0 }, free_from: null,
                        constraints: { max_weight: null, max_thickness: null }, rules: [], available: true };
                    var ed = modeEditor(draft, true);
                    draftHolder.innerHTML = '';
                    draftHolder.appendChild(ed.el);
                    nameInput.value = '';
                    ed.focus();
                } }, 'Ajouter ce mode manuel');
                return h('div', { class: 'ss-new-mode' },
                    h('h4', {}, 'Ajouter un mode manuel'),
                    h('p', { class: 'ss-muted' }, 'Le nouveau mode s’ouvre à la fin de la liste ci-dessus ; il n’est créé qu’après « Créer le mode ».'),
                    h('div', { class: 'ss-bar' },
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-new-mode-name' }, 'Nom du nouveau mode'), nameInput),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-new-mode-kind' }, 'Type'), kindSelect),
                        btn));
            }

            // ----- Contraintes globales (lois, offres commerciales) -----
            var EFFECTS = { minimum: 'Minimum', maximum: 'Maximum', fixed: 'Prix imposé' };
            var consModel = null;     // copie de travail de data.constraints
            var consDirty = false;    // modifications non enregistrées (conservées lors d'un rechargement)

            function cloneConstraints(list) { return JSON.parse(JSON.stringify(list || [])); }
            function emptyRule() { return { min_weight: null, max_weight: null, min_amount: null, max_amount: null, type: 'minimum', value: null, comment: '' }; }

            function drawConstraints(focusSelector) {
                if (consModel === null || !consDirty) {
                    consModel = cloneConstraints(data.constraints);
                }
                consBox.innerHTML = '';
                function touch() { consDirty = true; }
                function bindNum(obj, key, attrs) {
                    var input = numInput(obj[key], attrs);
                    input.addEventListener('input', function () { obj[key] = input.value === '' ? null : input.value; touch(); });
                    return input;
                }

                if (!consModel.length) {
                    consBox.appendChild(h('p', { class: 'ss-muted' }, 'Aucune contrainte : le prix de chaque mode s’applique tel quel.'));
                }

                consModel.forEach(function (c, i) {
                    var cid = 'ss-c-' + i;
                    var legend = h('legend', {}, (i + 1) + '. ' + (c.name || 'Sans nom'));
                    var name = h('input', { type: 'text', id: cid + '-name', value: c.name || '', maxlength: '80', required: true });
                    name.addEventListener('input', function () { c.name = name.value; legend.textContent = (i + 1) + '. ' + (name.value || 'Sans nom'); touch(); });

                    var types = (data.product_types || []).slice();
                    (c.product_types || []).forEach(function (t) { if (types.indexOf(t) === -1) { types.push(t); } });
                    var typeBoxes = types.map(function (t, k) {
                        var box = h('input', { type: 'checkbox', id: cid + '-t-' + k, value: t, checked: (c.product_types || []).indexOf(t) > -1, onchange: function () {
                            c.product_types = typeBoxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                            touch();
                        } });
                        return box;
                    });
                    var modeBoxes = data.modes.map(function (m, k) {
                        return h('input', { type: 'checkbox', id: cid + '-m-' + k, value: m.key, checked: (c.except_modes || []).indexOf(m.key) > -1, onchange: function () {
                            c.except_modes = modeBoxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                            touch();
                        } });
                    });

                    var tbody = h('tbody', {}, (c.rules || []).map(function (r, j) {
                        var n = ' (contrainte ' + (i + 1) + ', règle ' + (j + 1) + ')';
                        var effect = h('select', { 'aria-label': 'Effet' + n, onchange: function () { r.type = effect.value; touch(); } },
                            Object.keys(EFFECTS).map(function (k) { return h('option', { value: k, selected: k === r.type }, EFFECTS[k]); }));
                        var comment = h('input', { type: 'text', value: r.comment || '', maxlength: '300', 'aria-label': 'Commentaire' + n, class: 'ss-comment' });
                        comment.addEventListener('input', function () { r.comment = comment.value; touch(); });
                        var value = bindNum(r, 'value', { aria: 'Valeur (€ TTC)' + n });
                        value.required = true;
                        return h('tr', {},
                            h('td', {}, bindNum(r, 'min_weight', { aria: 'Poids à partir de (g)' + n, placeholder: '—' })),
                            h('td', {}, bindNum(r, 'max_weight', { aria: 'Poids jusqu’à (g)' + n, placeholder: '—' })),
                            h('td', {}, bindNum(r, 'min_amount', { aria: 'Total TTC à partir de (€)' + n, placeholder: '—' })),
                            h('td', {}, bindNum(r, 'max_amount', { aria: 'Total TTC jusqu’à (€)' + n, placeholder: '—' })),
                            h('td', {}, effect), h('td', {}, value), h('td', {}, comment),
                            h('td', {}, h('button', { class: 'ss-btn', type: 'button', 'aria-label': 'Supprimer la règle' + n, onclick: function () {
                                c.rules.splice(j, 1); touch(); drawConstraints('#' + cid + '-addrule');
                            } }, 'Supprimer')));
                    }));

                    function move(delta) {
                        var to = i + delta;
                        if (to < 0 || to >= consModel.length) { return; }
                        consModel.splice(to, 0, consModel.splice(i, 1)[0]);
                        touch();
                        drawConstraints('#ss-c-' + to + (delta < 0 ? '-up' : '-down'));
                    }

                    consBox.appendChild(h('fieldset', { class: 'ss-constraint', id: cid }, legend,
                        field('Nom de la contrainte', name),
                        types.length
                            ? h('fieldset', {}, h('legend', {}, 'Types de produits (blueprint)'),
                                h('div', { class: 'ss-inline' }, typeBoxes.map(function (b, k) { return h('div', { class: 'ss-field-row ss-check' }, b, h('label', { for: b.id }, types[k])); })),
                                h('p', { class: 'ss-muted' }, 'Aucun coché : tous les produits. Le total TTC comparé est celui des produits de ces types ; une contrainte limitée à des types ne s’applique que si le panier en contient.'))
                            : null,
                        h('fieldset', {}, h('legend', {}, 'Modes non concernés'),
                            h('div', { class: 'ss-inline' }, modeBoxes.map(function (b, k) { return h('div', { class: 'ss-field-row ss-check' }, b, h('label', { for: b.id }, data.modes[k].name)); }))),
                        h('div', { class: 'ss-table-scroll', role: 'region', tabindex: '0', 'aria-label': 'Règles de la contrainte ' + (i + 1) + ', défilement horizontal' },
                            h('table', {},
                                h('caption', { class: 'ss-sr' }, 'Règles de la contrainte ' + (c.name || (i + 1))),
                                h('thead', {}, h('tr', {}, ['Poids de (g)', 'Jusqu’à (g)', 'Total TTC de (€)', 'Jusqu’à (€)', 'Effet', 'Valeur (€ TTC)', 'Commentaire', ''].map(function (t) { return h('th', { scope: 'col' }, t); }))),
                                tbody)),
                        h('p', { class: 'ss-muted' }, 'Effet : « Minimum », le prix ne descend pas sous la valeur ; « Maximum », il ne la dépasse pas ; « Prix imposé », il est remplacé par la valeur.'),
                        h('div', { class: 'ss-bar' },
                            h('button', { class: 'ss-btn', type: 'button', id: cid + '-addrule', onclick: function () {
                                c.rules = c.rules || [];
                                c.rules.push(emptyRule()); touch();
                                drawConstraints('#' + cid + ' tbody tr:last-child input');
                            } }, 'Ajouter une règle'),
                            h('button', { class: 'ss-btn', type: 'button', id: cid + '-up', disabled: i === 0, onclick: function () { move(-1); } }, 'Monter'),
                            h('button', { class: 'ss-btn', type: 'button', id: cid + '-down', disabled: i === consModel.length - 1, onclick: function () { move(1); } }, 'Descendre'),
                            h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                                if (!window.confirm('Supprimer la contrainte « ' + (c.name || (i + 1)) + ' » ? (effectif après « Enregistrer les contraintes »)')) { return; }
                                consModel.splice(i, 1); touch(); drawConstraints('#ss-c-add');
                            } }, 'Supprimer cette contrainte'))));
                });

                var consErrors = h('div', { role: 'alert' });
                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                    consErrors.innerHTML = '';
                    saveBtn.disabled = true;
                    consStatus.className = 'ss-form-status';
                    consStatus.textContent = 'Enregistrement…';
                    api('PUT', '/carrier-constraints', { constraints: consModel })
                        .then(function () { consDirty = false; return reload({ constraints: 'Contraintes enregistrées.' }); })
                        .catch(function (e) {
                            consStatus.textContent = '';
                            consErrors.appendChild(h('div', { class: 'ss-msg err' }, e.message,
                                e.details && e.details.length ? h('ul', { class: 'ss-list' }, e.details.map(function (d) { return h('li', {}, d); })) : null));
                            saveBtn.disabled = false;
                        });
                } }, 'Enregistrer les contraintes');
                consBox.appendChild(h('div', { class: 'ss-bar' },
                    h('button', { class: 'ss-btn', type: 'button', id: 'ss-c-add', onclick: function () {
                        consModel.push({ name: '', product_types: [], except_modes: [], rules: [emptyRule()] });
                        touch();
                        drawConstraints('#ss-c-' + (consModel.length - 1) + '-name');
                    } }, 'Ajouter une contrainte'),
                    saveBtn,
                    consDirty ? h('span', { class: 'ss-muted' }, 'Modifications non enregistrées.') : null));
                consBox.appendChild(consErrors);
                consBox.appendChild(consStatus);

                if (focusSelector) {
                    var target = consBox.querySelector(focusSelector) || consBox.querySelector('#ss-c-add');
                    if (target && target.disabled) { target = consBox.querySelector('#ss-c-add'); }
                    if (target) { target.focus(); }
                }
            }

            function slug(name) {
                var s = name.normalize ? name.normalize('NFD').replace(/[̀-ͯ]/g, '') : name;
                s = s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
                return s.length >= 2 ? s : 'mode';
            }

            // ----- Simulateur -----
            function drawSimulator() {
                simBox.innerHTML = '';
                var zoneOptions = (data.zones && data.zones.length ? data.zones : data.all_zones);
                var f = {
                    weight: h('input', { type: 'number', id: 'ss-sim-w', min: '1', step: '1', value: '340', required: true }),
                    thickness: h('input', { type: 'number', id: 'ss-sim-t', min: '0', step: '1', value: '29', required: true }),
                    subtotal: h('input', { type: 'number', id: 'ss-sim-s', min: '0', step: 'any', value: '12.95', required: true }),
                    zone: h('select', { id: 'ss-sim-z' }, zoneOptions.map(function (z) { return h('option', { value: z }, z); })),
                    postal: h('input', { type: 'text', id: 'ss-sim-pc', maxlength: '5', inputmode: 'numeric', size: '6' }),
                    city: h('input', { type: 'text', id: 'ss-sim-city', maxlength: '120' })
                };
                var hasPickup = data.modes.some(function (m) { return m.kind === 'local_pickup'; });
                var simTypes = (data.product_types || []).map(function (t, k) { return h('input', { type: 'checkbox', id: 'ss-sim-type-' + k, value: t, checked: true }); });
                var out = h('div', { 'aria-live': 'polite' });
                var hasResult = false;
                simRefresh = function () { if (hasResult) { run(); } };
                function run() {
                    out.textContent = 'Calcul…';
                    api('POST', '/carriers/simulate', { weight: f.weight.value, thickness: f.thickness.value, subtotal: f.subtotal.value, zone: f.zone.value,
                        types: simTypes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }),
                        postal_code: f.postal.value, city: f.city.value })
                        .then(function (res) { hasResult = true; drawResult(res.data); })
                        .catch(function (e) { out.innerHTML = ''; self.fail(e); });
                }
                var form = h('form', { onsubmit: function (ev) { ev.preventDefault(); run(); } },
                    h('div', { class: 'ss-bar' },
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-w' }, 'Poids du colis, emballage compris (g)'), f.weight),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-t' }, 'Épaisseur (mm)'), f.thickness),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-s' }, 'Total TTC des produits (€)'), f.subtotal),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-z' }, 'Zone'), f.zone),
                        h('button', { class: 'ss-btn ss-primary', type: 'submit' }, 'Calculer')),
                    hasPickup ? h('div', { class: 'ss-bar' },
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-pc' }, 'Code postal du client (retrait sur place)'), f.postal),
                        h('div', { class: 'ss-field-row' }, h('label', { for: 'ss-sim-city' }, 'Ville du client'), f.city)) : null,
                    simTypes.length ? h('fieldset', { class: 'ss-sim-types' }, h('legend', {}, 'Le panier contient des produits de type (blueprint)'),
                        h('div', { class: 'ss-inline' }, simTypes.map(function (b) { return h('div', { class: 'ss-field-row ss-check' }, b, h('label', { for: b.id }, b.value)); }))) : null);
                simBox.appendChild(form);
                simBox.appendChild(out);

                function remarks(r) {
                    var q = r.quote, items = [];
                    if (r.reason) { items.push(r.reason); }
                    if (q && q.free_applied) { items.push('Offert dès le seuil du mode.'); }
                    (q ? q.constraints || [] : []).forEach(function (c) {
                        var effect = c.after !== c.before ? ' : ' + money(c.before) + ' → ' + money(c.after) : ' : sans effet (' + money(c.before) + ')';
                        items.push('« ' + c.name + ' »' + effect + (c.comment ? ' — ' + c.comment : ''));
                    });
                    if (r.pickup) { items.push('Le client choisit un point de retrait.'); }
                    if (q && q.distance_km !== undefined && q.distance_km !== null) { items.push('Client à ' + Number(q.distance_km).toLocaleString('fr-FR') + ' km du lieu de retrait.'); }
                    return items.length > 1 ? h('ul', { class: 'ss-list' }, items.map(function (t) { return h('li', {}, t); })) : (items[0] || '');
                }

                function drawResult(res) {
                    var ht = (data.settings || {}).prices_entered === 'ht';
                    out.innerHTML = '';
                    if (res.notice) { out.appendChild(h('p', { class: 'ss-muted' }, res.notice)); }
                    out.appendChild(h('div', { class: 'ss-table-scroll', role: 'region', tabindex: '0', 'aria-label': 'Résultat de la simulation' },
                        h('table', {},
                            h('caption', { class: 'ss-sr' }, 'Prix de chaque mode pour ce colis'),
                            h('thead', {}, h('tr', {}, ['Mode', 'Proposé au client', 'Grille ou forfait (' + (ht ? 'HT' : 'TTC') + ')', 'Avec marge', 'Avant contraintes (TTC)', 'Prix TTC', 'Contraintes et remarques'].map(function (t) { return h('th', { scope: 'col' }, t); }))),
                            h('tbody', {}, res.rows.map(function (r) {
                                var q = r.quote;
                                return h('tr', {},
                                    h('th', { scope: 'row' }, r.name),
                                    h('td', {}, r.offered ? 'Oui' : 'Non'),
                                    h('td', { class: 'ss-num' }, q ? money(q.grid_price) : '—'),
                                    h('td', { class: 'ss-num' }, q ? money(q.after_margin) : '—'),
                                    h('td', { class: 'ss-num' }, q ? money(q.price_before_constraints) : '—'),
                                    h('td', { class: 'ss-num' }, q ? h('strong', {}, money(q.price)) : '—'),
                                    h('td', {}, remarks(r)));
                            })))));
                }
            }

            // ----- YAML avancé -----
            function drawYaml() {
                yamlBox.innerHTML = '';
                var area = h('textarea', { class: 'ss-yaml', spellcheck: 'false', id: 'ss-yaml-area' });
                area.value = data.yaml;
                var saveBtn = h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () {
                    if (!window.confirm('Remplacer toute la configuration des modes par ce texte ?')) { return; }
                    saveBtn.disabled = true;
                    api('PUT', '/carriers', { yaml: area.value })
                        .then(function () { return reload(); })
                        .then(function () {
                            yamlStatus.textContent = 'Configuration enregistrée (l’ancienne version est conservée en carriers.yaml.bak).';
                            yamlStatus.focus();
                        })
                        .catch(function (e) { self.fail(e); })
                        .then(function () { saveBtn.disabled = false; });
                } }, 'Enregistrer le YAML');
                yamlBox.appendChild(h('p', { class: 'ss-muted' }, 'Réservé aux cas particuliers : l’écran ci-dessus suffit normalement. Le texte est vérifié avant enregistrement.'));
                yamlBox.appendChild(h('label', { for: 'ss-yaml-area', class: 'ss-sr' }, 'Configuration YAML des transporteurs'));
                yamlBox.appendChild(area);
                yamlBox.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem' }, saveBtn));
                var yamlStatus = h('p', { class: 'ss-done', role: 'status', tabindex: '-1' });
                yamlBox.appendChild(yamlStatus);
            }

            reload();
        }

        // ---------------- Adresses bannies ----------------

        // ---------------- Taxes ----------------

        /**
         * Taxes : résumé des réglages, puis éditeur des TYPES DE TAXE (ex. « Taux réduit », choisis par les types de produits et
         * les fiches) et de leurs TAUX (un ou plusieurs par type : variantes par pays, taxes cumulées). Chaque type est un
         * <details> ouvert, chacun de ses taux un <details> fermé. Import/export CSV (ex. taux de chaque pays de l'UE pour le
         * guichet unique OSS). Les autres réglages se modifient dans Plugins > Simple Shop > Taxes.
         */
        showTaxes() {
            var self = this;
            var RATE_KEYS = ['rate', 'country', 'region', 'postcodes', 'label', 'priority', 'compound', 'shipping', 'category', 'mention'];
            var COLS = ['type', 'taux', 'pays', 'region', 'codes_postaux', 'libelle', 'priorite', 'composee', 'livraison', 'categorie', 'mention'];
            var CATEGORIES = [['S', 'Taux normal ou réduit'], ['Z', 'Taux zéro'], ['E', 'Exonéré (mention obligatoire)'], ['O', 'Hors champ de la TVA']];
            var box = h('div');
            var SHIPPING = { items: 'selon les articles du panier (panier mixte : taux le plus élevé)', items_prorata: 'selon les articles, au prorata du HT' };
            var BASED = { shipping: 'adresse de livraison du client', billing: 'adresse de facturation du client', shop: 'adresse de la boutique' };
            var uid = 0;

            function pct(v) { return String(v === '' || v === undefined || v === null ? '?' : v).replace('.', ',') + ' %'; }
            function newRate(country) {
                return { rate: '', country: country || '', region: '', postcodes: '', label: '', priority: 1, compound: false, shipping: true, category: 'S', mention: '' };
            }
            function rateSummary(r) {
                var where = (r.country || 'tous pays') + (r.region ? ' / ' + r.region : '') + (r.postcodes ? ' / ' + r.postcodes : '');
                return where + ' · ' + pct(r.rate) + (r.label ? ' · ' + r.label : '') + (Number(r.priority) > 1 ? ' · priorité ' + r.priority : '')
                    + (r.compound ? ' · composée' : '') + (r.shipping ? '' : ' · hors livraison');
            }

            function toCsv(types) {
                var lines = [COLS.join(';')];
                types.forEach(function (t) {
                    t.rates.forEach(function (r) {
                        lines.push([t.name].concat(RATE_KEYS.map(function (k) {
                            var v = r[k];
                            if (typeof v === 'boolean') { return v ? 'oui' : 'non'; }
                            if (k === 'rate') { return String(v).replace('.', ','); }
                            return String(v === null || v === undefined ? '' : v);
                        })).map(function (v) { return String(v).replace(/;/g, ','); }).join(';'));
                    });
                });
                return '﻿' + lines.join('\r\n') + '\r\n';
            }

            // CSV → types imbriqués (lignes du même type regroupées dans l'ordre du fichier).
            function fromCsv(text) {
                var lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
                if (!lines.length) { return []; }
                var delim = lines[0].indexOf(';') > -1 ? ';' : (lines[0].indexOf('\t') > -1 ? '\t' : ',');
                var byName = {};
                var out = [];
                lines.forEach(function (line, i) {
                    var c = line.split(delim).map(function (x) { return x.trim().replace(/^"|"$/g, ''); });
                    if (i === 0 && /^(type|nom|classe)$/i.test(c[0])) { return; }
                    var yes = function (v, d) { return v === '' || v === undefined ? d : /^(oui|yes|1|true|vrai)$/i.test(v); };
                    if (!byName[c[0]]) { byName[c[0]] = { name: c[0], rates: [] }; out.push(byName[c[0]]); }
                    byName[c[0]].rates.push({ rate: (c[1] || '').replace(',', '.'), country: c[2] || '', region: c[3] || '', postcodes: c[4] || '',
                        label: c[5] || '', priority: parseInt(c[6] || '1', 10) || 1, compound: yes(c[7], false), shipping: yes(c[8], true),
                        category: c[9] || '', mention: c[10] || '' });
                });
                return out;
            }

            function save(types, after) {
                return api('PUT', '/taxes/types', { tax_types: types })
                    .then(function (res) { self.notify(res.data.types + ' types de taxe, ' + res.data.count + ' taux enregistrés.', false); if (after) { after(); } load(); })
                    .catch(function (e) { self.fail(e); });
            }

            // Champ d'un taux, lié au modèle ; `onChange` met à jour le résumé du <details>.
            function rateField(r, key, label, onChange) {
                var id = 'ss-tx-' + (++uid);
                var input;
                if (key === 'compound' || key === 'shipping') {
                    input = h('input', { type: 'checkbox', id: id, checked: !!r[key], onchange: function () { r[key] = input.checked; onChange(); } });
                    return h('div', { class: 'ss-field-row ss-check' }, input, h('label', { for: id }, label));
                }
                if (key === 'category') {
                    input = h('select', { id: id, onchange: function () { r[key] = input.value; onChange(); } },
                        CATEGORIES.map(function (c) { return h('option', { value: c[0], selected: (r.category || 'S') === c[0] }, c[1]); }));
                } else {
                    input = h('input', { type: key === 'priority' ? 'number' : 'text', id: id, value: r[key] === undefined || r[key] === null ? '' : String(r[key]),
                        min: key === 'priority' ? '1' : null, inputmode: key === 'rate' ? 'decimal' : null, maxlength: key === 'country' ? '2' : null,
                        placeholder: { rate: '5.5', country: 'FR (vide = tous)', label: 'TVA 5,5 %', postcodes: '20* ; 97100...97199' }[key] || null,
                        oninput: function () { r[key] = key === 'priority' ? (parseInt(input.value, 10) || 1) : (key === 'country' ? input.value.toUpperCase() : input.value); onChange(); } });
                }
                return h('div', { class: 'ss-field-row' }, h('label', { for: id }, label), input);
            }

            function drawEditor(container, types) {
                container.innerHTML = '';
                types.forEach(function (t, ti) {
                    var title = h('span', { class: 'ss-mode-name' }, t.name || '(sans nom)');
                    var count = h('span', { class: 'ss-muted' });
                    function refreshType() {
                        title.textContent = t.name || '(sans nom)';
                        count.textContent = t.rates.length === 1 ? '— ' + rateSummary(t.rates[0]) : '— ' + t.rates.length + ' taux';
                    }
                    refreshType();
                    var nameId = 'ss-tx-' + (++uid);
                    var nameInput = h('input', { type: 'text', id: nameId, value: t.name, oninput: function () { t.name = nameInput.value; refreshType(); } });
                    var ratesBox = h('div');
                    t.rates.forEach(function (r, ri) {
                        var sum = h('span', {}, rateSummary(r));
                        var update = function () { sum.textContent = rateSummary(r); refreshType(); };
                        ratesBox.appendChild(h('details', { class: 'ss-grid-details', 'data-rate': ri },
                            h('summary', {}, h('span', { class: 'ss-muted' }, 'Taux ' + (ri + 1) + ' :'), sum),
                            h('div', { class: 'ss-inline' },
                                rateField(r, 'rate', 'Taux (%)', update), rateField(r, 'country', 'Pays (code à deux lettres)', update),
                                rateField(r, 'label', 'Libellé sur la facture', update), rateField(r, 'priority', 'Priorité', update)),
                            h('div', { class: 'ss-inline' },
                                rateField(r, 'region', 'Région (code)', update), rateField(r, 'postcodes', 'Codes postaux', update),
                                rateField(r, 'category', 'Catégorie (facture électronique)', update), rateField(r, 'mention', 'Mention (exonération)', update)),
                            h('div', { class: 'ss-inline' }, rateField(r, 'compound', 'Composée (calculée sur le prix déjà taxé)', update),
                                rateField(r, 'shipping', "S'applique aux frais de livraison", update)),
                            h('p', {}, h('button', { class: 'ss-btn', type: 'button', disabled: t.rates.length < 2, onclick: function () {
                                t.rates.splice(ri, 1); drawEditor(container, types);
                            } }, 'Supprimer ce taux'))));
                    });
                    container.appendChild(h('details', { class: 'ss-mode', open: true },
                        h('summary', {}, title, count),
                        h('div', { class: 'ss-mode-form' },
                            h('div', { class: 'ss-field-row' }, h('label', { for: nameId }, 'Nom du type de taxe (choisi par les types de produits et les fiches)'), nameInput),
                            ratesBox,
                            h('div', { class: 'ss-bar' },
                                h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                                    t.rates.push(newRate(''));
                                    drawEditor(container, types);
                                    var last = container.querySelectorAll('details.ss-mode')[ti].querySelectorAll('details.ss-grid-details');
                                    if (last.length) { last[last.length - 1].open = true; last[last.length - 1].querySelector('input').focus(); }
                                } }, 'Ajouter un taux (autre pays…)'),
                                h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                                    if (!window.confirm('Supprimer le type de taxe « ' + (t.name || '') + ' » et ses ' + t.rates.length + ' taux ?')) { return; }
                                    types.splice(ti, 1); drawEditor(container, types);
                                } }, 'Supprimer ce type')))));
                });
            }

            function draw(t) {
                box.innerHTML = '';
                var types = JSON.parse(JSON.stringify(t.tax_types));
                box.appendChild(h('p', {}, (t.enabled ? 'Taxes activées' : 'Taxes désactivées') + ' ; prix des fiches saisis ' + t.prices_entered.toUpperCase()
                    + ' ; taux calculé d’après l’' + (BASED[t.based_on] || t.based_on) + ' ; livraison : '
                    + (SHIPPING[t.shipping_tax] || ('toujours le type « ' + t.shipping_tax + ' »')) + '.'));
                if (t.small_business.enabled) { box.appendChild(h('p', { class: 'ss-muted' }, 'Franchise en base : ' + t.small_business.mention)); }

                box.appendChild(h('h3', {}, 'Type de taxe de chaque type de produit'));
                box.appendChild(h('table', {}, h('tbody', {}, Object.keys(t.product_types).map(function (type) {
                    return h('tr', {}, h('td', {}, type), h('td', {}, t.product_types[type]));
                }).concat([h('tr', {}, h('td', { class: 'ss-muted' }, 'autres produits'), h('td', {}, t.default_type))]))));
                box.appendChild(h('p', { class: 'ss-muted' }, 'Ces correspondances, le type par défaut et les autres réglages : Plugins > Simple Shop > Taxes.'));

                box.appendChild(h('h3', {}, 'Types de taxe et taux'));
                box.appendChild(h('p', { class: 'ss-muted' }, 'Un type de taxe contient un ou plusieurs taux. Pour vendre dans un autre pays, ajoutez au type un taux pour ce pays '
                    + '(ex. « Taux réduit » : FR 5,5 %, BE 6 %). À l’adresse du client, le taux le plus précis l’emporte (code postal, région, pays, puis « tous pays ») ; '
                    + 'deux priorités différentes donnent deux taxes cumulées.'));
                var editor = h('div');
                drawEditor(editor, types);
                box.appendChild(editor);

                var file = h('input', { type: 'file', accept: '.csv,text/csv,text/plain', class: 'ss-sr', tabindex: '-1', onchange: function () {
                    var f = file.files && file.files[0];
                    if (!f) { return; }
                    f.text().then(function (text) {
                        var imported = fromCsv(text);
                        file.value = '';
                        if (!imported.length) { self.notify('Aucun taux dans ce fichier.', true); return; }
                        var n = imported.reduce(function (s, x) { return s + x.rates.length; }, 0);
                        if (!window.confirm('Remplacer tous les types de taxe par les ' + imported.length + ' types (' + n + ' taux) du fichier ?')) { return; }
                        save(imported);
                    });
                } });
                box.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem' },
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        types.push({ name: '', rates: [newRate('FR')] });
                        drawEditor(editor, types);
                        var all = editor.querySelectorAll('details.ss-mode');
                        all[all.length - 1].querySelector('input').focus();
                    } }, 'Ajouter un type de taxe'),
                    h('button', { class: 'ss-btn ss-primary', type: 'button', onclick: function () { save(types); } }, 'Enregistrer les types de taxe'),
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () { load(); } }, 'Annuler les modifications')));
                box.appendChild(h('div', { class: 'ss-bar', style: 'margin-top:.8rem' },
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () {
                        download(new Blob([toCsv(t.tax_types)], { type: 'text/csv;charset=utf-8' }), 'taux-de-taxe.csv');
                    } }, 'Exporter en CSV'),
                    h('button', { class: 'ss-btn', type: 'button', onclick: function () { file.click(); } }, 'Importer un CSV (remplace tout)…'), file));
                box.appendChild(h('p', { class: 'ss-muted' }, 'CSV : une ligne par taux, colonnes ' + COLS.join(' ; ') + ' (séparateur « ; », virgule décimale, oui/non ; '
                    + 'les lignes d’un même type sont regroupées). L’export reflète la dernière version enregistrée.'));
            }

            function load() {
                api('GET', '/taxes').then(function (res) { draw(res.data); }).catch(function (e) { self.fail(e); });
            }

            this.body.appendChild(box);
            load();
        }

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
