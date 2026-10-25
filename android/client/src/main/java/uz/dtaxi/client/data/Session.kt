package uz.dtaxi.client.data

import android.content.Context
import android.content.SharedPreferences
import uz.dtaxi.core.data.AppLocale
import uz.dtaxi.core.data.SecurePrefs

/** Token, til va mavzu — shifrlangan saqlash */
class Session(context: Context) {
    private val prefs: SharedPreferences = SecurePrefs.open(context)

    var token: String?
        get() = prefs.getString("token", null)
        set(value) = prefs.edit().putString("token", value).apply()

    /** Ilova tili: uz, oz (kirill), ru, en */
    var locale: String
        get() = AppLocale.normalize(prefs.getString("locale", "uz"))
        set(value) = prefs.edit().putString("locale", AppLocale.normalize(value)).apply()

    /** Mavzu: system, light, dark */
    var themeMode: String
        get() = prefs.getString("theme_mode", "system") ?: "system"
        set(value) = prefs.edit().putString("theme_mode", value).apply()

    fun clear() = prefs.edit().remove("token").apply()
}
