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

    /**
     * Oxirgi faol buyurtma — ilova fonda (yoki yopiq) bo'lganda bekor qilinsa, qaytganda nima bo'lganini
     * tarixdan topib ko'rsatish uchun
     */
    var activeOrderId: Int?
        get() = prefs.getInt("active_order_id", 0).takeIf { it > 0 }
        set(value) = prefs.edit().putInt("active_order_id", value ?: 0).apply()

    /** Kirishdagi ism qadamida "Keyinroq" bosilgan — har ochilishda qayta so'ralmaydi */
    var nameSkipped: Boolean
        get() = prefs.getBoolean("name_skipped", false)
        set(value) = prefs.edit().putBoolean("name_skipped", value).apply()

    /** "Keyinroq" bosilgan safarlar — server har ochilishda to_rate qaytaradi, oyna qayta chiqmasin */
    fun isRatingDismissed(orderId: Int): Boolean = orderId in dismissedRatings()

    fun dismissRating(orderId: Int) {
        // Oxirgi 20 tasi yetarli: server faqat 2 soat ichidagi safarni taklif qiladi
        val ids = (dismissedRatings() + orderId).toList().takeLast(20)
        prefs.edit().putString("dismissed_ratings", ids.joinToString(",")).apply()
    }

    private fun dismissedRatings(): LinkedHashSet<Int> =
        prefs.getString("dismissed_ratings", null).orEmpty().split(',').mapNotNullTo(LinkedHashSet()) { it.toIntOrNull() }

    fun clear() = prefs.edit().remove("token").remove("active_order_id").remove("name_skipped").apply()
}
