package com.pkgalla.app;

import android.os.Bundle;
import android.webkit.WebSettings;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    /**
     * Mixed content is allowed in debug builds only.
     *
     * The app is served from https://localhost (androidScheme in
     * capacitor.config.json), so a call to a plaintext dev API on
     * http://10.0.2.2:8000 is mixed content and the webview blocks it before it
     * leaves the device — the same failure the debug network-security config
     * next door exists to fix, one layer up.
     *
     * capacitor.config.json keeps `allowMixedContent: false` so the release APK
     * gets MIXED_CONTENT_NEVER_ALLOW: on shop wifi, an http subresource loading
     * into the origin that holds the bearer token is an injection point, and a
     * config flag is far too easy to ship flipped. BuildConfig.DEBUG cannot be
     * shipped flipped — it is false in every release build by definition.
     */
    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (BuildConfig.DEBUG && getBridge() != null && getBridge().getWebView() != null) {
            WebSettings settings = getBridge().getWebView().getSettings();
            settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        }
    }
}
