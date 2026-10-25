package uz.dtaxi.core.data

/** Reverb (Pusher protokoli) ulanishi — server profil bilan beradi (API bilan bir xil manzil, nginx /app) */
data class ReverbConfig(val key: String, val host: String, val port: Int, val scheme: String)
