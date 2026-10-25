package uz.dtaxi.core.ui

import android.app.Activity
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import uz.dtaxi.core.data.Formats


/** Material sxemasida yo'q ranglar: yashil (liniyada, qabul), xira matn, navigator banneri */
data class AppColors(
    val success: Color,
    val onSuccess: Color,
    val muted: Color,
    val tile: Color,
    val dark: Boolean,
)

val LocalAppColors = staticCompositionLocalOf {
    AppColors(Color(0xFF1FA463), Color.White, Color(0xFF555555), Color(0xFFF4F4F2), false)
}

/** Navigator banneri ikkala rejimda ham qora, strelka sariq */
val NavBannerColor = Color(0xFF111111)

/** Qora banner ustidagi ikkinchi darajali matn (ko'cha nomi) */
val NavBannerMuted = Color(0xFFCFCFCF)

// A — "Sariq klassik" (kunduzgi); brand — firma rangi (firm.properties → primaryColor)
private fun light(brand: Color) = lightColorScheme(
    primary = brand,
    onPrimary = Color(0xFF111111),
    primaryContainer = brand,
    onPrimaryContainer = Color(0xFF111111),
    secondary = Color(0xFF111111),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFF4F4F2),
    onSecondaryContainer = Color(0xFF111111),
    tertiaryContainer = Color(0xFFE6F6EC),
    onTertiaryContainer = Color(0xFF11713F),
    background = Color(0xFFFFFFFF),
    onBackground = Color(0xFF111111),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF111111),
    surfaceVariant = Color(0xFFF4F4F2),
    onSurfaceVariant = Color(0xFF555555),
    surfaceContainer = Color(0xFFFFFFFF),
    surfaceTint = Color(0xFFFFFFFF),
    surfaceContainerHigh = Color(0xFFF4F4F2),
    surfaceContainerHighest = Color(0xFFF4F4F2),
    outline = Color(0xFFD9D7D0),
    outlineVariant = Color(0xFFE7E5DF),
    error = Color(0xFFD92D20),
)

// B — "Tungi rejim" (qorong'i)
private fun dark(brand: Color) = darkColorScheme(
    primary = brand,
    onPrimary = Color(0xFF111111),
    primaryContainer = brand,
    onPrimaryContainer = Color(0xFF111111),
    secondary = Color(0xFFF2F2F2),
    onSecondary = Color(0xFF0F1115),
    secondaryContainer = Color(0xFF262A31),
    onSecondaryContainer = Color(0xFFF2F2F2),
    tertiaryContainer = Color(0xFF173528),
    onTertiaryContainer = Color(0xFF34D399),
    background = Color(0xFF0F1115),
    onBackground = Color(0xFFF2F2F2),
    surface = Color(0xFF1C2027),
    onSurface = Color(0xFFF2F2F2),
    surfaceVariant = Color(0xFF262A31),
    onSurfaceVariant = Color(0xFFA6ADBA),
    surfaceContainer = Color(0xFF1C2027),
    surfaceTint = Color(0xFF1C2027),
    surfaceContainerHigh = Color(0xFF1C2027),
    surfaceContainerHighest = Color(0xFF262A31),
    outline = Color(0xFF2E333C),
    outlineVariant = Color(0xFF2E333C),
    error = Color(0xFFF87171),
)

/** Raqamlar va sarlavhalar maketlardagidek qalin (shrift yuklanmaydi — tizim shrifti) */
private val typography = Typography().let { t ->
    t.copy(
        displaySmall = t.displaySmall.copy(fontWeight = FontWeight.ExtraBold),
        headlineMedium = t.headlineMedium.copy(fontWeight = FontWeight.ExtraBold),
        headlineSmall = t.headlineSmall.copy(fontWeight = FontWeight.ExtraBold),
        titleLarge = t.titleLarge.copy(fontWeight = FontWeight.ExtraBold),
        titleMedium = t.titleMedium.copy(fontWeight = FontWeight.Bold),
        labelLarge = t.labelLarge.copy(fontWeight = FontWeight.Bold),
    )
}

/**
 * Ilovalar mavzusi. brandHex — firma rangi (Dtaxi: sariq), mode — sozlamadagi tanlov: system / light / dark.
 */
@Composable
fun AppTheme(brandHex: String, mode: String, content: @Composable () -> Unit) {
    val brand = runCatching { Color(android.graphics.Color.parseColor(brandHex)) }.getOrDefault(Color(0xFFFFC800))
    val isDark = when (mode) {
        "light" -> false
        "dark" -> true
        else -> isSystemInDarkTheme()
    }
    val scheme = if (isDark) dark(brand) else light(brand)
    val extra = if (isDark) {
        AppColors(Color(0xFF34D399), Color(0xFF0B1F16), Color(0xFFA6ADBA), Color(0xFF262A31), true)
    } else {
        AppColors(Color(0xFF1FA463), Color.White, Color(0xFF555555), Color(0xFFF4F4F2), false)
    }
    // Holat paneli belgilari fonga mos: yorug' rejimda oq fonda oq soat/batareya ko'rinmay qolardi
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            (view.context as? Activity)?.window?.let { window ->
                @Suppress("DEPRECATION")
                window.statusBarColor = scheme.background.toArgb()
                @Suppress("DEPRECATION")
                window.navigationBarColor = scheme.background.toArgb()
                WindowCompat.getInsetsController(window, view).apply {
                    isAppearanceLightStatusBars = !isDark
                    isAppearanceLightNavigationBars = !isDark
                }
            }
        }
    }
    // Fon mavzu rangidan: aks holda telefonning qorong'i rejimida oq oyna ustida och matn ko'rinmay qoladi
    CompositionLocalProvider(LocalAppColors provides extra) {
        MaterialTheme(colorScheme = scheme, typography = typography) {
            Surface(Modifier.fillMaxSize(), color = scheme.background, content = content)
        }
    }
}

/** Qorong'i rejimda xarita ham qorong'i uslubda (server: /map/style.json?flavor=dark) */
fun mapStyleFor(url: String, dark: Boolean): String {
    val base = url.replace(Regex("([?&])flavor=[^&]*&?"), "$1").trimEnd('&', '?')
    if (!dark) return base
    return base + (if (base.contains('?')) "&" else "?") + "flavor=dark"
}

fun formatMoney(value: Long?): String = Formats.formatMoney(value)
