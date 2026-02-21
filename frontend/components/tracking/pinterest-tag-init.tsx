'use client'

// Lädt das Pinterest Tag Script NUR nach Cookie Consent.
// Wird in app/layout.tsx eingebunden.
// Prüft Consent beim Mount und bei localStorage-Änderungen.

import { useEffect, useState } from 'react'
import Script from 'next/script'
import { hasConsent } from '@/lib/tracking/pinterest-tag'

const PINTEREST_TAG_ID = process.env.NEXT_PUBLIC_PINTEREST_TAG_ID ?? ''

export function PinterestTagInit() {
  const [consentGiven, setConsentGiven] = useState(false)

  useEffect(() => {
    // Initialer Check
    if (hasConsent()) {
      setConsentGiven(true)
      return
    }

    // Listener für Consent-Änderung (Custom Event von CookieBanner aus Slice 4)
    const handleConsentChange = () => {
      if (hasConsent()) {
        setConsentGiven(true)
      }
    }

    window.addEventListener('cookie-consent-accepted', handleConsentChange)
    return () => window.removeEventListener('cookie-consent-accepted', handleConsentChange)
  }, [])

  if (!consentGiven || !PINTEREST_TAG_ID) return null

  return (
    <>
      {/* Pinterest Tag Base Code – geladen nach Consent (strategy: afterInteractive) */}
      <Script
        id="pinterest-tag"
        strategy="afterInteractive"
        dangerouslySetInnerHTML={{
          __html: `
            !function(e){if(!window.pintrk){window.pintrk=function(){
            window.pintrk.queue.push(Array.prototype.slice.call(arguments))};
            var n=window.pintrk;n.queue=[],n.version="3.0";
            var t=document.createElement("script");
            t.async=!0,t.src=e;
            var r=document.getElementsByTagName("script")[0];
            r.parentNode.insertBefore(t,r)}}
            ("https://s.pinimg.com/ct/core.js");
            pintrk('load', '${PINTEREST_TAG_ID}', {np: 'next'});
          `,
        }}
      />
      {/* Pinterest No-Script Fallback */}
      <noscript>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          height="1"
          width="1"
          style={{ display: 'none' }}
          alt=""
          src={`https://ct.pinterest.com/v3/?event=init&tid=${PINTEREST_TAG_ID}&noscript=1`}
        />
      </noscript>
    </>
  )
}
