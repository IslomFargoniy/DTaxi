package uz.dtaxi.core.net

import com.google.gson.Gson
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Response
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Server xato xabari (Laravel: {"message": "..."}) */
data class ApiError(val message: String?)

object HttpClients {
    private val gson = Gson()

    /**
     * Har so'rovda joriy server manzili (sinov ilovasida login ekranida o'zgarishi mumkin), til va token.
     */
    fun create(serverUrl: () -> String, token: () -> String?, locale: () -> String, debug: Boolean): OkHttpClient =
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                // Noto'g'ri manzil (sinov ilovasida qo'lda kiritilgan) — tarmoq xatosi, ilova yiqilmasin
                // (interceptor'dagi IllegalArgumentException OkHttp oqimida ilovani to'xtatadi)
                val server = serverUrl().toHttpUrlOrNull() ?: throw IOException("Invalid server URL")
                val url = chain.request().url.newBuilder().scheme(server.scheme).host(server.host).port(server.port).build()
                val request = chain.request().newBuilder()
                    .url(url)
                    .header("Accept", "application/json")
                    .header("Accept-Language", locale())
                    .apply { token()?.let { header("Authorization", "Bearer $it") } }
                    .build()
                chain.proceed(request)
            }
            .apply {
                if (debug) addInterceptor(HttpLoggingInterceptor().setLevel(HttpLoggingInterceptor.Level.BASIC))
            }
            .build()

    fun <T> errorMessage(response: Response<T>): String? =
        runCatching { gson.fromJson(response.errorBody()?.charStream(), ApiError::class.java)?.message }.getOrNull()
}
