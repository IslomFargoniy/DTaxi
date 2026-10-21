package uz.ibtaxi.driver.data

/**
 * Operator haydovchiga qo'ng'iroq qilyapti (qaror #59). Ilova SIP'da ro'yxatda turmaydi:
 * "Javob berish" bosilsa qo'ng'iroq sahifasi o'zi [target] ga qo'ng'iroq qiladi va operator bilan ulanadi.
 */
data class IncomingCall(val callId: Int, val operator: String, val expiresIn: Int) {
    val target: String get() = "answer-$callId"

    companion object {
        /**
         * Push kechikib kelsa — qolgan vaqt. Telefon soati noto'g'ri bo'lsa (farq manfiy yoki muddatdan katta)
         * to'liq muddat olinadi: aks holda qo'ng'iroq umuman ko'rinmay qolardi.
         */
        fun remaining(expiresIn: Int, sentAt: Long?, nowSeconds: Long): Int {
            val elapsed = sentAt?.let { nowSeconds - it } ?: return expiresIn
            return if (elapsed in 0..expiresIn.toLong()) expiresIn - elapsed.toInt() else expiresIn
        }
    }
}
