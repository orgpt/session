/**
 * AET – Arabic ↔ English Translator  |  translator.js
 *
 * Responsibilities:
 *  1. Read AET.lang config injected by PHP.
 *  2. Walk the DOM and collect text nodes that need translation.
 *  3. Batch-request translations via WP AJAX (server proxies Google Translate).
 *  4. Cache results in localStorage to avoid duplicate requests.
 *  5. Observe DOM mutations (for AJAX / WooCommerce dynamic content).
 *  6. Handle language-switcher button clicks.
 *  7. Rewrite internal links to include ?lang= param.
 *  8. Swap html[lang] and html[dir] without reload.
 */

( function () {
    'use strict';

    /* ── Guard ─────────────────────────────────────────────────── */
    if ( typeof window.AET === 'undefined' ) return;

    const CFG = window.AET;
    const DEFAULT_LANG = CFG.defaultLang || 'en';
    let   currentLang  = CFG.lang        || DEFAULT_LANG;

    /* ── LocalStorage cache ────────────────────────────────────── */
    const Cache = {
        key: ( src, tgt, text ) => CFG.cachePrefix + src + '_' + tgt + '_' + btoa( encodeURIComponent( text.trim().slice( 0, 80 ) ) ),

        get ( src, tgt, text ) {
            try { return localStorage.getItem( this.key( src, tgt, text ) ); } catch { return null; }
        },

        set ( src, tgt, text, translated ) {
            try { localStorage.setItem( this.key( src, tgt, text ), translated ); } catch {}
        },
    };

    /* ── Excluded selectors (from PHP config) ──────────────────── */
    const EXCLUDED = ( CFG.excludeSelectors || '' )
        .split( ',' )
        .map( s => s.trim() )
        .filter( Boolean );

    /* ── Helpers ────────────────────────────────────────────────── */

    /**
     * Returns true if an element (or any ancestor) matches excluded selectors.
     */
    function isExcluded ( node ) {
        let el = node.nodeType === Node.TEXT_NODE ? node.parentElement : node;
        if ( ! el ) return false;

        // Never translate AET switcher itself
        if ( el.closest( '.aet-switcher' ) ) return true;

        for ( const sel of EXCLUDED ) {
            try { if ( el.closest( sel ) ) return true; } catch {}
        }
        return false;
    }

    /**
     * Collect text nodes from a root element that have visible, non-empty text.
     */
    function collectTextNodes ( root ) {
        const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, {
            acceptNode ( node ) {
                if ( ! node.textContent.trim() ) return NodeFilter.FILTER_REJECT;
                if ( isExcluded( node ) )        return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            },
        } );

        const nodes = [];
        while ( walker.nextNode() ) nodes.push( walker.currentNode );
        return nodes;
    }

    /**
     * Translate an array of strings from `src` to `tgt`.
     * Returns a parallel array of translated strings.
     */
    async function batchTranslate ( texts, src, tgt ) {
        if ( src === tgt || ! texts.length ) return texts;

        const results    = new Array( texts.length );
        const toFetch    = [];
        const fetchIdx   = [];

        // Cache pass
        texts.forEach( ( t, i ) => {
            const cached = Cache.get( src, tgt, t );
            if ( cached !== null ) {
                results[ i ] = cached;
            } else {
                toFetch.push( t );
                fetchIdx.push( i );
            }
        } );

        if ( ! toFetch.length ) return results;

        try {
            const resp = await fetch( CFG.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify( {
                    action: 'aet_translate',
                    nonce:  CFG.nonce,
                    texts:  toFetch,
                    source: src,
                    target: tgt,
                } ),
            } );

            const json = await resp.json();
            if ( json.success && Array.isArray( json.data.translations ) ) {
                json.data.translations.forEach( ( translated, ii ) => {
                    const origIdx   = fetchIdx[ ii ];
                    const origText  = texts[ origIdx ];
                    results[ origIdx ] = translated;
                    Cache.set( src, tgt, origText, translated );
                } );
            }
        } catch ( err ) {
            // Network failure – fall back to originals
            console.warn( '[AET] Translation fetch failed:', err );
        }

        // Fill any missing (error) slots with originals
        return results.map( ( r, i ) => ( r === undefined ? texts[ i ] : r ) );
    }

    /* ── DOM translation ────────────────────────────────────────── */

    /** Map from original text → current TextNode list (so we can restore) */
    const nodeOriginalMap = new WeakMap(); // node → original text

    async function translateRoot ( root, src, tgt ) {
        if ( src === tgt ) return;

        const nodes = collectTextNodes( root );
        if ( ! nodes.length ) return;

        // Snapshot originals (store once)
        nodes.forEach( n => {
            if ( ! nodeOriginalMap.has( n ) ) {
                nodeOriginalMap.set( n, n.textContent );
            }
        } );

        const texts = nodes.map( n => nodeOriginalMap.get( n ) );
        const translated = await batchTranslate( texts, src, tgt );

        nodes.forEach( ( n, i ) => {
            if ( translated[ i ] && translated[ i ] !== n.textContent ) {
                n.textContent = translated[ i ];
            }
        } );
    }

    /** Restore all text nodes to their original English (or site-default) text. */
    function restoreRoot ( root ) {
        const nodes = collectTextNodes( root );
        nodes.forEach( n => {
            const orig = nodeOriginalMap.get( n );
            if ( orig !== undefined ) n.textContent = orig;
        } );
    }

    /* ── Link rewriting ─────────────────────────────────────────── */

    function rewriteLinks ( lang ) {
        document.querySelectorAll( 'a[href]' ).forEach( a => {
            try {
                const url = new URL( a.href, window.location.href );
                // Only internal links
                if ( url.hostname !== window.location.hostname ) return;
                // Don't touch mailto / tel / hash-only
                if ( ! url.pathname ) return;

                url.searchParams.set( 'lang', lang );
                a.href = url.toString();
            } catch {}
        } );
    }

    /* ── html lang + dir ────────────────────────────────────────── */

    function applyDirection ( lang ) {
        const html = document.documentElement;
        html.setAttribute( 'lang', lang );
        html.setAttribute( 'dir',  lang === 'ar' ? 'rtl' : 'ltr' );
        // body class for Woodmart / WooCommerce CSS
        document.body.classList.remove( 'rtl', 'ltr', 'aet-lang-ar', 'aet-lang-en' );
        document.body.classList.add( lang === 'ar' ? 'rtl' : 'ltr', 'aet-lang-' + lang );
    }

    /* ── Cookie helper (JS side – mirrors PHP) ──────────────────── */

    function setCookie ( name, value, days ) {
        const exp = new Date( Date.now() + days * 864e5 ).toUTCString();
        document.cookie = name + '=' + encodeURIComponent( value ) +
            ';expires=' + exp + ';path=/;SameSite=Lax';
    }

    /* ── Persist language preference ────────────────────────────── */

    function persistLang ( lang ) {
        setCookie( 'aet_lang', lang, 365 );
        try { localStorage.setItem( 'aet_lang', lang ); } catch {}
    }

    /* ── Full-page language switch ──────────────────────────────── */

    async function switchLanguage ( targetLang ) {
        if ( targetLang === currentLang ) return;

        document.body.classList.add( 'aet-translating' );

        const prevLang = currentLang;
        currentLang    = targetLang;

        persistLang( targetLang );
        applyDirection( targetLang );
        updateSwitcherUI( targetLang );
        rewriteLinks( targetLang );

        const defaultLang = DEFAULT_LANG;

        if ( targetLang !== defaultLang ) {
            // Translate away from default
            await translateRoot( document.body, defaultLang, targetLang );
        } else {
            // Restore to original
            restoreRoot( document.body );
        }

        // Update page title
        if ( targetLang !== defaultLang ) {
            const titleEl = document.querySelector( 'title' );
            if ( titleEl ) {
                const orig  = nodeOriginalMap.get( titleEl.childNodes[0] ) || titleEl.textContent;
                const parts = await batchTranslate( [ orig ], defaultLang, targetLang );
                titleEl.textContent = parts[0] || orig;
            }
        }

        document.body.classList.remove( 'aet-translating' );

        // Notify WooCommerce / other scripts
        window.dispatchEvent( new CustomEvent( 'aet:language-changed', { detail: { lang: targetLang } } ) );
    }

    /* ── Switcher button UI state ───────────────────────────────── */

    function updateSwitcherUI ( lang ) {
        document.querySelectorAll( '.aet-btn' ).forEach( btn => {
            const active = btn.dataset.lang === lang;
            btn.classList.toggle( 'aet-btn--active', active );
            btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
        } );
    }

    /* ── Event delegation for switcher buttons ──────────────────── */

    document.addEventListener( 'click', e => {
        const btn = e.target.closest( '.aet-btn[data-lang]' );
        if ( ! btn ) return;
        e.preventDefault();
        switchLanguage( btn.dataset.lang );
    } );

    /* ── MutationObserver for dynamic content ───────────────────── */

    const observer = new MutationObserver( mutations => {
        if ( currentLang === DEFAULT_LANG ) return;

        for ( const m of mutations ) {
            for ( const node of m.addedNodes ) {
                if ( node.nodeType === Node.ELEMENT_NODE ) {
                    // Small debounce to batch rapid DOM changes (e.g. WooCommerce cart)
                    clearTimeout( node._aetTimer );
                    node._aetTimer = setTimeout( () => {
                        translateRoot( node, DEFAULT_LANG, currentLang );
                    }, 120 );
                }
            }
        }
    } );

    observer.observe( document.body, { childList: true, subtree: true } );

    /* ── Initial load ───────────────────────────────────────────── */

    async function init () {
        applyDirection( currentLang );
        updateSwitcherUI( currentLang );
        rewriteLinks( currentLang );

        if ( currentLang !== DEFAULT_LANG ) {
            await translateRoot( document.body, DEFAULT_LANG, currentLang );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

    /* ── AJAX / fetch intercept – re-translate new content ──────── */
    // WooCommerce uses fetch() for cart fragments; retranslate after updates
    if ( typeof window.fetch === 'function' && currentLang !== DEFAULT_LANG ) {
        const _origFetch = window.fetch.bind( window );
        window.fetch = async ( ...args ) => {
            const resp = await _origFetch( ...args );
            // Clone is needed since body can only be consumed once
            const clone = resp.clone();
            clone.text().then( () => {
                // After the caller has consumed its copy, re-translate any
                // new nodes that the MutationObserver hasn't handled yet.
                setTimeout( () => {
                    translateRoot( document.body, DEFAULT_LANG, currentLang );
                }, 400 );
            } ).catch( () => {} );
            return resp;
        };
    }

    /* ── Expose public API ──────────────────────────────────────── */
    window.AET.switchLanguage = switchLanguage;
    window.AET.getCurrentLang = () => currentLang;

} )();
