package uz.ibtaxi.driver.data

import com.google.gson.Gson
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import uz.ibtaxi.driver.BuildConfig
import java.util.concurrent.TimeUnit

/**
 * Server manzili firma konfiguratsiyasidan (firms/<firma>/firm.properties → BuildConfig).
 */
class ApiClient(private val session: Session) {
    val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val request = chain.request().newBuilder()
                .header("Accept", "application/json")
                .header("Accept-Language", session.locale)
                .apply { session.token?.let { header("Authorization", "Bearer $it") } }
                .build()
            chain.proceed(request)
        }
        .apply {
            if (BuildConfig.DEBUG) {
                addInterceptor(HttpLoggingInterceptor().setLevel(HttpLoggingInterceptor.Level.BASIC))
            }
        }
        .build()

    val api: Api = Retrofit.Builder()
        .baseUrl(BuildConfig.SERVER_URL + "/")
        .client(http)
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(Api::class.java)

    companion object {
        private val gson = Gson()

        /** Server xato xabari (Laravel: {"message": "..."}) */
        fun <T> errorMessage(response: Response<T>): String? =
            runCatching { gson.fromJson(response.errorBody()?.charStream(), ApiError::class.java)?.message }.getOrNull()
    }
}
