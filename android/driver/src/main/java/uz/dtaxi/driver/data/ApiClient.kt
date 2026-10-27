package uz.dtaxi.driver.data

import okhttp3.OkHttpClient
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import uz.dtaxi.core.net.HttpClients
import uz.dtaxi.driver.BuildConfig

/**
 * Server manzili firma konfiguratsiyasidan (firms/<firma>/firm.properties → BuildConfig).
 */
class ApiClient(private val session: Session) {
    val http: OkHttpClient = HttpClients.create({ session.serverUrl }, { session.token }, { session.locale }, BuildConfig.DEBUG)
        .newBuilder()
        .addInterceptor { chain ->
            chain.proceed(chain.request()).also { response ->
                response.headers.getDate("Date")?.let { ServerClock.onServerDate(it.time, System.currentTimeMillis()) }
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
        fun <T> errorMessage(response: Response<T>): String? = HttpClients.errorMessage(response)
    }
}
