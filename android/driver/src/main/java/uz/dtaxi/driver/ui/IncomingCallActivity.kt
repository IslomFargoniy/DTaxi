package uz.dtaxi.driver.ui

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.KeyEvent
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.CallEnd
import androidx.compose.material.icons.filled.HeadsetMic
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.core.data.AppLocale
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.IncomingCall
import uz.dtaxi.driver.service.IncomingCallNotifier

/**
 * Operator qo'ng'irog'i ekrani (qaror #59): qulflangan ekranda ham ochiladi va ekranni yoqadi.
 * Ko'rinishi qo'ng'iroq sahifasi (driver-call.blade.php) bilan bir xil: tepada kim, o'rtada belgi, pastda tugmalar.
 */
class IncomingCallActivity : ComponentActivity() {
    /** Javob berildi — ekran yopilishi "qo'ng'iroq tugadi" bilan adashtirilmasin */
    private var answering = false

    private val micLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            openCall()
        } else {
            DriverState.toast(getString(R.string.mic_required))
            IncomingCallNotifier.decline(this)
            finish()
        }
    }

    override fun attachBaseContext(base: Context) {
        super.attachBaseContext(AppLocale.wrap(base, DtaxiApp.instance.session.locale))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setShowWhenLocked(true)
        setTurnScreenOn(true)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        if (IncomingCallNotifier.current.value == null) {
            finish()
            return
        }
        setContent {
            val call by IncomingCallNotifier.current.collectAsState()
            call?.let { IncomingCallScreen(it, onAnswer = ::answer, onDecline = ::decline) }
        }
        // Operator qo'ydi, rad etildi yoki vaqt o'tdi — ekran yopiladi
        lifecycleScope.launch {
            IncomingCallNotifier.current.collect { if (it == null && !answering) finish() }
        }
        handle(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handle(intent)
    }

    /** Bildirishnomadagi "Javob berish" tugmasi shu ekran orqali keladi */
    private fun handle(intent: Intent?) {
        if (intent?.action == ACTION_ANSWER) answer()
    }

    private fun answer() {
        if (answering) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            openCall()
        } else {
            IncomingCallNotifier.silence()
            micLauncher.launch(Manifest.permission.RECORD_AUDIO)
        }
    }

    private fun openCall() {
        answering = true
        val call = IncomingCallNotifier.answer(this)
        if (call != null) {
            startActivity(
                Intent(this, CallActivity::class.java)
                    .putExtra(CallActivity.EXTRA_TARGET, call.target)
                    .putExtra(CallActivity.EXTRA_CALLER, call.operator),
            )
        }
        finish()
    }

    private fun decline() {
        IncomingCallNotifier.decline(this)
        finish()
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        // Telefondagidek: ovoz tugmasi jiringlashni o'chiradi
        if (keyCode == KeyEvent.KEYCODE_VOLUME_DOWN || keyCode == KeyEvent.KEYCODE_VOLUME_UP) {
            IncomingCallNotifier.silence()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    companion object {
        const val ACTION_ANSWER = "uz.dtaxi.driver.ANSWER_CALL"

        fun intent(context: Context): Intent = Intent(context, IncomingCallActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
    }
}

private val Brand = Color(0xFFFFC800)
private val Red = Color(0xFFEF3B3B)
private val Green = Color(0xFF22C55E)

@Composable
private fun IncomingCallScreen(call: IncomingCall, onAnswer: () -> Unit, onDecline: () -> Unit) {
    val mode by DriverState.themeMode.collectAsState()
    val dark = when (mode) {
        "light" -> false
        "dark" -> true
        else -> isSystemInDarkTheme()
    }
    val background = if (dark) {
        Brush.verticalGradient(0f to Color(0xFF1C2333), 0.65f to Color(0xFF0D1119))
    } else {
        Brush.verticalGradient(0f to Color(0xFFEEF1F6), 0.6f to Color.White)
    }
    val fg = if (dark) Color(0xFFF3F4F6) else Color(0xFF111827)
    val muted = if (dark) Color(0xFF9CA3AF) else Color(0xFF6B7280)

    val pulse = rememberInfiniteTransition(label = "pulse")
    val wave by pulse.animateFloat(
        initialValue = 0f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(tween(2000, easing = LinearEasing), RepeatMode.Restart),
        label = "wave",
    )

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(background)
            .systemBarsPadding()
            .padding(start = 24.dp, end = 24.dp, top = 72.dp, bottom = 56.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            call.operator.ifBlank { stringResource(R.string.dispatcher) },
            color = fg,
            fontSize = 30.sp,
            fontWeight = FontWeight.Normal,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(10.dp))
        Text(stringResource(R.string.incoming_call), color = muted, fontSize = 16.sp)

        Spacer(Modifier.weight(1f))
        Box(
            modifier = Modifier
                .size(128.dp)
                .drawBehind {
                    // Sahifadagi "pulse" halqasi
                    drawCircle(Brand.copy(alpha = 0.45f * (1f - wave)), radius = size.minDimension / 2 + 28.dp.toPx() * wave)
                }
                .clip(CircleShape)
                .background(Brand),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Filled.HeadsetMic, contentDescription = null, tint = Color(0xFF111827), modifier = Modifier.size(64.dp))
        }
        Spacer(Modifier.weight(1f))

        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
            CallButton(stringResource(R.string.decline), Icons.Filled.CallEnd, Red, muted, onDecline)
            CallButton(stringResource(R.string.answer), Icons.Filled.Call, Green, muted, onAnswer)
        }
    }
}

@Composable
private fun CallButton(
    label: String,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    color: Color,
    labelColor: Color,
    onClick: () -> Unit,
) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        IconButton(
            onClick = onClick,
            modifier = Modifier
                .size(76.dp)
                .shadow(12.dp, CircleShape, ambientColor = color, spotColor = color),
            colors = IconButtonDefaults.iconButtonColors(containerColor = color, contentColor = Color.White),
        ) {
            Icon(icon, contentDescription = label, modifier = Modifier.size(34.dp))
        }
        Spacer(Modifier.height(8.dp))
        Text(label, color = labelColor, fontSize = 13.sp)
    }
}
