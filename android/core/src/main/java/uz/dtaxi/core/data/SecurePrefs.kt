package uz.dtaxi.core.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Token va sozlamalar — shifrlangan saqlash (fayl nomlari o'zgarmasin: yangilangan ilova tizimdan chiqib ketmasin).
 */
object SecurePrefs {
    private const val FILE = "session"
    private const val PLAIN_FILE = "session_plain"

    private fun encrypted(context: Context): SharedPreferences = EncryptedSharedPreferences.create(
        context,
        FILE,
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    /**
     * Ba'zi telefonlarda (OEM keystore) kalit buziladi va har ochilishda ilova yiqilardi. Buzilgan fayl va kalit
     * o'chiriladi (foydalanuvchi qayta kiradi); baribir ishlamasa — oddiy saqlash, keyingi ochilishlarda ham shu.
     */
    fun open(context: Context): SharedPreferences {
        val plain = context.getSharedPreferences(PLAIN_FILE, Context.MODE_PRIVATE)
        if (plain.getBoolean("fallback", false)) return plain
        return try {
            encrypted(context)
        } catch (e: Exception) {
            android.util.Log.w("SecurePrefs", "Shifrlangan saqlash ochilmadi — tozalanadi", e)
            context.deleteSharedPreferences(FILE)
            runCatching {
                java.security.KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
                    .deleteEntry(MasterKey.DEFAULT_MASTER_KEY_ALIAS)
            }
            try {
                encrypted(context)
            } catch (e: Exception) {
                android.util.Log.w("SecurePrefs", "Shifrlangan saqlash ishlamaydi — oddiy saqlash", e)
                plain.edit().putBoolean("fallback", true).commit()
                plain
            }
        }
    }
}
