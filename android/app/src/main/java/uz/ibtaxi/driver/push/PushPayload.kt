package uz.ibtaxi.driver.push

import com.google.gson.Gson
import com.google.gson.JsonObject
import uz.ibtaxi.driver.data.IncomingCall
import uz.ibtaxi.driver.data.Offer

/**
 * FCM data — faqat satrlar. Server bool'ni "1"/"" qilib yuboradi (PHP strval), null esa "" bo'lib kelishi
 * mumkin: bo'sh qiymat Gson'ga berilsa Int?/Long? maydonida xato — butun taklif yo'qolardi.
 */
object PushPayload {
    private val gson = Gson()
    private val booleans = setOf("is_direct", "is_delivery", "is_corporate")
    private val requiredText = setOf("pickup_text", "tariff_class")

    fun offer(data: Map<String, String>): Offer? {
        val json = JsonObject()
        data.forEach { (key, value) ->
            when {
                key in booleans -> json.addProperty(key, value == "1" || value == "true")
                value.isBlank() -> Unit
                else -> json.addProperty(key, value)
            }
        }
        if (!json.has("offer_id") || !json.has("order_id")) return null
        // Kotlin'da null bo'lmaydigan matn maydonlari — Gson ularni null qoldirsa ekran yiqiladi
        requiredText.forEach { if (!json.has(it)) json.addProperty(it, "") }
        return runCatching { gson.fromJson(json, Offer::class.java) }.getOrNull()
    }

    /** Operator qo'ng'irog'i: call_id, operator, expires_in, sent_at (server vaqti, soniya) */
    fun incomingCall(data: Map<String, String>, nowSeconds: Long): IncomingCall? {
        val callId = data["call_id"]?.toIntOrNull() ?: return null
        val expiresIn = data["expires_in"]?.toIntOrNull() ?: 30
        return IncomingCall(
            callId,
            data["operator"].orEmpty(),
            IncomingCall.remaining(expiresIn, data["sent_at"]?.toLongOrNull(), nowSeconds),
        )
    }
}
