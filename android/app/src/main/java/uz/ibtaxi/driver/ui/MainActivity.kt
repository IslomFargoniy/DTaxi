package uz.ibtaxi.driver.ui

import androidx.compose.ui.unit.dp
import androidx.compose.ui.Modifier
import androidx.compose.material3.TextButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Arrangement
import kotlinx.coroutines.delay
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.activity.compose.BackHandler
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
import uz.ibtaxi.driver.data.AppLocale
import uz.ibtaxi.driver.data.AppVersion
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.service.DriverService
import uz.ibtaxi.driver.service.OfferNotifier
import uz.ibtaxi.driver.update.Updater

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
        // Ilova tili (uz, oz — kirill, ru, en): telefon tilidan qat'i nazar
        super.attachBaseContext(AppLocale.wrap(base, IbTaxiApp.instance.session.locale))
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
                // rememberSaveable: til yoki rejim almashib Activity qayta yaratilsa ham ochiq ekran saqlanadi
                var screen by rememberSaveable { mutableStateOf(if (intent?.getStringExtra("open") == "chat") Screen.Chat else Screen.Home) }
                // Tizimning "orqaga" tugmasi ichki ekrandan bosh ekranga (ilovadan chiqib ketmasin)
                BackHandler(enabled = screen != Screen.Home && screen != Screen.Call) { screen = Screen.Home }
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
                    app.repository.latestVersion()?.takeIf(updater::isNewer)?.let { update = it }
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
                    LoginScreen(onLocaleChange = { code ->
                        app.session.locale = code
                        recreate()
                    })
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
                        Screen.Chat -> ChatScreen(onBack = { screen = Screen.Home }, onCall = ::callDispatcher)
                        Screen.Balance -> BalanceScreen { screen = Screen.Home }
                        Screen.Settings -> SettingsScreen(onBack = { screen = Screen.Home }, onLocaleChange = { code ->
                            app.session.locale = code
                            // Server xabarlari va xarita nomlari ham shu tilda (ilova scope'ida — ekran qayta yaratilsa ham)
                            app.scope.launch { app.repository.setLocale(code) }
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
                        onExpire = { DriverState.expireOffer(current) },
                    )
                }

                // Ilova ochiq turganda ham yangi versiya chiqsa bilinsin — har 30 daqiqada /android/version.json
                LaunchedEffect(Unit) {
                    // Kirishdan oldin ham (eski ilova login ekranida qolib ketmasin), keyin har 30 daqiqada
                    while (true) {
                        if (update == null) app.repository.latestVersion()?.takeIf(updater::isNewer)?.let { update = it }
                        delay(30 * 60_000L)
                    }
                }

                update?.let { version ->
                    val required = updater.isRequired(version)
                    var progress by remember(version.versionCode) { mutableStateOf<Int?>(null) }
                    var failed by remember(version.versionCode) { mutableStateOf(false) }
                    AlertDialog(
                        onDismissRequest = { if (!required && progress == null) update = null },
                        title = { Text(stringResource(R.string.update_available) + (version.versionName?.let { " ($it)" } ?: "")) },
                        text = {
                            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                if (required) Text(stringResource(R.string.update_required))
                                progress?.let { p ->
                                    if (p >= 0) {
                                        LinearProgressIndicator(progress = { p / 100f }, modifier = Modifier.fillMaxWidth())
                                        Text("${stringResource(R.string.update_downloading)} $p%")
                                    } else {
                                        LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                                    }
                                }
                                if (failed) Text(stringResource(R.string.update_failed), color = MaterialTheme.colorScheme.error)
                            }
                        },
                        confirmButton = {
                            Button(
                                enabled = progress == null || failed,
                                onClick = {
                                    failed = false
                                    progress = 0
                                    scope.launch {
                                        val ok = updater.downloadAndInstall(version) { progress = it }
                                        if (!ok) failed = true
                                        progress = null
                                    }
                                },
                            ) { Text(stringResource(R.string.update_now)) }
                        },
                        dismissButton = {
                            if (!required && progress == null) TextButton(onClick = { update = null }) { Text(stringResource(R.string.later)) }
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

    // Tez-tez ikki marta bosilsa ikkita qo'ng'iroq oynasi ochilib, SIP ulanishlari to'qnashardi (qora ekran)
    private var lastCallAt = 0L

    private fun callDispatcher() {
        val now = android.os.SystemClock.elapsedRealtime()
        if (now - lastCallAt < 1_500) return
        lastCallAt = now
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            startActivity(Intent(this, CallActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
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
