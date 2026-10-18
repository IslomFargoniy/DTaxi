package uz.ibtaxi.driver.data

import uz.ibtaxi.driver.BuildConfig
import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Token va til — shifrlangan saqlash.
 */
class Session(context: Context) {
    private val prefs: SharedPreferences = EncryptedSharedPreferences.create(
        context,
        "session",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var token: String?
        get() = prefs.getString("token", null)
        set(value) = prefs.edit().putString("token", value).apply()

    var locale: String
        get() = prefs.getString("locale", "uz") ?: "uz"
        set(value) = prefs.edit().putString("locale", value).apply()

    var batteryHintShown: Boolean
        get() = prefs.getBoolean("battery_hint", false)
        set(value) = prefs.edit().putBoolean("battery_hint", value).apply()

    /** Navigatsiyada ovozli ko'rsatmalar */
    var voiceGuidance: Boolean
        get() = prefs.getBoolean("voice_guidance", true)
        set(value) = prefs.edit().putBoolean("voice_guidance", value).apply()

    /** Server manzili: firma konfiguratsiyasidan; sinov ilovasida (SERVER_EDITABLE) login ekranida o'zgartiriladi */
    var serverUrl: String
        get() = prefs.getString("server_url", null)?.takeIf { BuildConfig.SERVER_EDITABLE && it.isNotBlank() } ?: BuildConfig.SERVER_URL
        set(value) = prefs.edit().putString("server_url", value.trim().trimEnd('/')).apply()

    fun clear() = prefs.edit().remove("token").apply()
}
