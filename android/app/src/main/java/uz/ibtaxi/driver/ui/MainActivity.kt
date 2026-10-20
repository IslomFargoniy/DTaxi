package uz.ibtaxi.driver.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.AppVersion
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.service.DriverService
import uz.ibtaxi.driver.service.OfferNotifier
import uz.ibtaxi.driver.update.Updater
import java.util.Locale

enum class Screen { Home, Chat, Balance, Settings, Call }

class MainActivity : ComponentActivity() {
    private var afterPermissions: (() -> Unit)? = null

    private val permissionLauncher = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        if (result[Manifest.permission.ACCESS_FINE_LOCATION] == true) afterPermissions?.invoke()
        afterPermissions = null
    }

    private val micLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) startActivity(Intent(this, CallActivity::class.java)) else DriverState.toast(getString(R.string.mic_required))
    }

    override fun attachBaseContext(base: Context) {
        // Ilova tili (o'zbek/rus — qaror #44)
        val locale = Locale(IbTaxiApp.instance.session.locale)
        val config = base.resources.configuration.apply { setLocale(locale) }
        super.attachBaseContext(base.createConfigurationContext(config))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as IbTaxiApp
        val updater = Updater(this, app.repository.client.http)

        setContent {
            IbTaxiTheme {
                val profile by DriverState.profile.collectAsState()
                val offer by DriverState.offer.collectAsState()
                val toast by DriverState.toast.collectAsState()
                var screen by remember { mutableStateOf(if (intent?.getStringExtra("open") == "chat") Screen.Chat else Screen.Home) }
                var update by remember { mutableStateOf<AppVersion?>(null) }
                val scope = rememberCoroutineScope()
                // Server bilan aloqa yo'q (masalan sinov ilovasida Mac IP'si o'zgardi): xabar, qayta urinish, chiqish
                var loadError by remember { mutableStateOf<String?>(null) }
                var attempt by remember { mutableStateOf(0) }
                var signOuts by remember { mutableStateOf(0) }
                val loggedIn = signOuts >= 0 && app.repository.isLoggedIn

                LaunchedEffect(loggedIn, attempt) {
                    if (!loggedIn) return@LaunchedEffect
                    loadError = null
                    val result = app.repository.refreshProfile()
                    if (result is Result.Error) {
                        if (result.code == 401) {
                            app.session.clear()
                            signOuts++
                        } else {
                            loadError = result.message ?: getString(R.string.network_error)
                        }
                        return@LaunchedEffect
                    }
                    app.repository.refreshOrder()
                    registerPush()
                    (app.repository.appVersion() as? Result.Ok)?.value?.takeIf(updater::isNewer)?.let { update = it }
                    // Ilova qayta ochilganda liniyadagi haydovchi uchun servis davom etadi
                    if (DriverState.profile.value?.status != "offline" && hasLocationPermission()) DriverService.start(this@MainActivity)
                }
                LaunchedEffect(toast) {
                    toast?.let {
                        Toast.makeText(this@MainActivity, it, Toast.LENGTH_LONG).show()
                        DriverState.toast(null)
                    }
                }

                if (profile == null && !loggedIn) {
                    LoginScreen()
                } else if (profile == null) {
                    ConnectingScreen(
                        error = loadError,
                        server = app.session.serverUrl,
                        onRetry = { attempt++ },
                        onLogout = {
                            app.session.clear()
                            signOuts++
                        },
                    )
                } else {
                    when (screen) {
                        Screen.Home, Screen.Call -> HomeScreen(onOpen = { target ->
                            if (target == Screen.Call) callDispatcher() else screen = target
                        }, requestPermissions = ::withPermissions)
                        Screen.Chat -> ChatScreen { screen = Screen.Home }
                        Screen.Balance -> BalanceScreen { screen = Screen.Home }
                        Screen.Settings -> SettingsScreen(onBack = { screen = Screen.Home }, onLocaleChange = { code ->
                            app.session.locale = code
                            recreate()
                        })
                    }
                }

                offer?.let { current ->
                    OfferDialog(
                        offer = current,
                        onAccept = {
                            OfferNotifier.cancel(this)
                            scope.launch {
                                val result = app.repository.accept(current.orderId)
                                if (result is Result.Error) DriverState.toast(result.message ?: getString(R.string.order_taken))
                            }
                        },
                        onReject = {
                            OfferNotifier.cancel(this)
                            scope.launch { app.repository.reject(current.orderId) }
                        },
                        onExpire = { DriverState.clearOffer(current.orderId) },
                    )
                }

                update?.let { version ->
                    val required = updater.isRequired(version)
                    AlertDialog(
                        onDismissRequest = { if (!required) update = null },
                        title = { Text(stringResource(R.string.update_available)) },
                        text = { if (required) Text(stringResource(R.string.update_required)) },
                        confirmButton = {
                            Button(onClick = { scope.launch { updater.downloadAndInstall(version) } }) { Text(stringResource(R.string.update_now)) }
                        },
                    )
                }
            }
        }
    }

    private fun withPermissions(then: () -> Unit) {
        val needed = buildList {
            if (!hasLocationPermission()) add(Manifest.permission.ACCESS_FINE_LOCATION)
            if (Build.VERSION.SDK_INT >= 33 &&
                ContextCompat.checkSelfPermission(this@MainActivity, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
            ) {
                add(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        if (needed.isEmpty()) {
            then()
        } else {
            afterPermissions = then
            permissionLauncher.launch(needed.toTypedArray())
        }
    }

    private fun callDispatcher() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            startActivity(Intent(this, CallActivity::class.java))
        } else {
            micLauncher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    private fun hasLocationPermission() =
        ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun registerPush() {
        if (FirebaseApp.getApps(this).isEmpty()) return
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            val app = application as IbTaxiApp
            kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.IO).launch { app.repository.registerPushToken(token) }
        }
    }
}
