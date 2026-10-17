package uz.ibtaxi.driver.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import uz.ibtaxi.driver.BuildConfig

/** Firma rangi — firm.properties → primaryColor */
private val brand = runCatching { Color(android.graphics.Color.parseColor(BuildConfig.PRIMARY_COLOR)) }.getOrDefault(Color(0xFFF5B700))

@Composable
fun IbTaxiTheme(content: @Composable () -> Unit) {
    val scheme = if (isSystemInDarkTheme()) {
        darkColorScheme(primary = brand, onPrimary = Color.Black, secondary = brand)
    } else {
        lightColorScheme(primary = brand, onPrimary = Color.Black, secondary = brand)
    }
    MaterialTheme(colorScheme = scheme, content = content)
}

fun formatMoney(value: Long?): String = value?.let { "%,d".format(it).replace(',', ' ') } ?: "—"
