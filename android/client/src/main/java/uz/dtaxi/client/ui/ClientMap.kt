package uz.dtaxi.client.ui

import android.os.Bundle
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Dp
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.Point
import uz.dtaxi.client.data.LatLng
import org.maplibre.android.geometry.LatLng as MapLatLng

/** O'zbekiston markazi — firma filiali hali noma'lum bo'lsa */
private val UZBEKISTAN = MapLatLng(41.38, 64.59)

/**
 * Xarita buyruqlari ekrandan: joylashuvga o'tish, buyurtma nuqtalarini sig'dirish. Hammasi ko'rinadigan maydon
 * bo'yicha — yuqoridagi tugma va pastki panel (top/bottom, px) ostidagi joy hisobga olinmaydi.
 */
class MapController {
    internal var map: MapLibreMap? = null
    internal var top = 0.0
    internal var bottom = 0.0

    fun moveTo(point: LatLng, zoom: Double = 16.0) {
        val map = map ?: return
        map.animateCamera(
            CameraUpdateFactory.newCameraPosition(
                CameraPosition.Builder().target(MapLatLng(point.lat, point.lng)).zoom(zoom).padding(0.0, top, 0.0, bottom).build(),
            ),
        )
    }

    /** Hozirgi markaz (ko'rinadigan maydon o'rtasi) */
    fun center(): LatLng? = map?.cameraPosition?.target?.let { LatLng(it.latitude, it.longitude) }

    fun fit(points: List<LatLng>) {
        val map = map ?: return
        when {
            points.isEmpty() -> Unit
            points.size == 1 -> moveTo(points.first())
            else -> {
                val bounds = LatLngBounds.Builder().apply { points.forEach { include(MapLatLng(it.lat, it.lng)) } }.build()
                val margin = 120
                map.animateCamera(CameraUpdateFactory.newLatLngBounds(bounds, margin, top.toInt() + margin, margin, bottom.toInt() + margin))
            }
        }
    }
}

/**
 * Mijoz xaritasi. picking — "Qayerdan" tanlash: markazda pin, xarita to'xtaganda onCenter(markaz).
 * Belgilar (A, B, haydovchi) — berilganlari chiziladi (chaqiruvchi tanlaydi).
 */
@Composable
fun ClientMap(
    styleUrl: String,
    initialCenter: LatLng?,
    controller: MapController,
    picking: Boolean,
    pickup: LatLng?,
    dropoff: LatLng?,
    driver: LatLng?,
    modifier: Modifier = Modifier,
    // Xarita ustidagi panellar: markaz (pin) va kamera shu oraliqning o'rtasida
    topInset: Dp = 0.dp,
    bottomInset: Dp = 0.dp,
    onCenter: (LatLng) -> Unit = {},
    onMoveStarted: () -> Unit = {},
    // Markazdagi pin rangi ("Qayerga" tanlanayotganda — qizil); null — mavzu matni rangida
    pinColor: Color? = null,
) {
    val context = LocalContext.current
    val density = LocalDensity.current
    controller.top = with(density) { topInset.toPx() }.toDouble()
    controller.bottom = with(density) { bottomInset.toPx() }.toDouble()
    // Qorong'i xaritada qora belgi ko'rinmasdi: pin — mavzu matni rangida, haydovchi — firma rangida
    val pinTint = pinColor ?: MaterialTheme.colorScheme.onSurface
    val brand = MaterialTheme.colorScheme.primary.toArgb()
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val styleHolder = remember { arrayOfNulls<Style>(1) }
    // Mavzu almashsa uslub (kunduzgi/tungi) qayta yuklanadi; versiya o'zgargach belgilar qayta chiziladi
    val loadedStyle = remember { arrayOfNulls<String>(1) }
    var styleVersion by remember { mutableIntStateOf(0) }
    // Tinglovchilar xarita bir marta yaratilganda o'rnatiladi — eng oxirgi qiymatlar shu orqali
    val state = remember { arrayOf<Any?>(picking, onCenter, onMoveStarted) }
    state[0] = picking
    state[1] = onCenter
    state[2] = onMoveStarted

    DisposableEffect(lifecycle) {
        var started = false
        var resumed = false
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart().also { started = true }
                Lifecycle.Event.ON_RESUME -> mapView.onResume().also { resumed = true }
                Lifecycle.Event.ON_PAUSE -> mapView.onPause().also { resumed = false }
                Lifecycle.Event.ON_STOP -> mapView.onStop().also { started = false }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            if (resumed) mapView.onPause()
            if (started) mapView.onStop()
            mapView.onDestroy()
            controller.map = null
        }
    }

    Box(modifier) {
        AndroidView(factory = {
            mapView.also { view ->
                view.getMapAsync { map ->
                    controller.map = map
                    map.uiSettings.isRotateGesturesEnabled = false
                    map.uiSettings.isCompassEnabled = false
                    map.uiSettings.isAttributionEnabled = true
                    // Undan yaqinda xarita fayllari yo'q — bo'sh kulrang ekran ko'rinardi
                    map.setMaxZoomPreference(19.0)
                    map.cameraPosition = CameraPosition.Builder()
                        .target(initialCenter?.let { MapLatLng(it.lat, it.lng) } ?: UZBEKISTAN)
                        .zoom(if (initialCenter != null) 14.0 else 5.0)
                        .padding(0.0, controller.top, 0.0, controller.bottom).build()
                    map.addOnCameraMoveStartedListener { reason ->
                        @Suppress("UNCHECKED_CAST")
                        if (reason == MapLibreMap.OnCameraMoveStartedListener.REASON_API_GESTURE) (state[2] as () -> Unit)()
                    }
                    map.addOnCameraIdleListener {
                        @Suppress("UNCHECKED_CAST")
                        if (state[0] == true) {
                            val target = map.cameraPosition.target ?: return@addOnCameraIdleListener
                            (state[1] as (LatLng) -> Unit)(LatLng(target.latitude, target.longitude))
                        }
                    }
                    loadStyle(map, styleUrl, brand) { style ->
                        styleHolder[0] = style
                        loadedStyle[0] = styleUrl
                        styleVersion++
                    }
                }
            }
        }, modifier = Modifier.fillMaxSize())

        // Tanlash rejimi: pin ko'rinadigan maydon markazida (kamera padding'i bilan bir xil), uchi aynan markaz nuqtasida
        if (picking) {
            Box(Modifier.fillMaxSize().padding(top = topInset, bottom = bottomInset)) {
                Icon(
                    Icons.Default.Place,
                    contentDescription = null,
                    tint = pinTint,
                    modifier = Modifier.align(Alignment.Center).size(44.dp).offset(y = (-20).dp),
                )
            }
        }
    }

    // Panel balandligi o'zgardi — kamera padding'i yangilanadi (markaz o'sha nuqtada qoladi, endi ko'rinadigan joyda)
    LaunchedEffect(controller.top, controller.bottom) {
        val map = controller.map ?: return@LaunchedEffect
        map.moveCamera(CameraUpdateFactory.newCameraPosition(CameraPosition.Builder(map.cameraPosition).padding(0.0, controller.top, 0.0, controller.bottom).build()))
    }

    LaunchedEffect(styleUrl) {
        val map = controller.map ?: return@LaunchedEffect
        if (loadedStyle[0] != null && loadedStyle[0] != styleUrl) {
            styleHolder[0] = null
            loadStyle(map, styleUrl, brand) { style ->
                styleHolder[0] = style
                loadedStyle[0] = styleUrl
                styleVersion++
            }
        }
    }

    LaunchedEffect(picking, pickup, dropoff, driver, styleVersion) {
        styleHolder[0]?.let { update(it, pickup, dropoff, driver) }
    }
}

private const val SOURCE = "client-points"

/** Uslub va belgilar qatlami (A — yashil, B — qizil, haydovchi — firma rangida qora hoshiya bilan) */
private fun loadStyle(map: MapLibreMap, url: String, brand: Int, onReady: (Style) -> Unit) {
    map.setStyle(Style.Builder().fromUri(url)) { style ->
        style.addSource(GeoJsonSource(SOURCE))
        style.addLayer(
            CircleLayer("points", SOURCE).withProperties(
                PropertyFactory.circleRadius(Expression.match(Expression.get("kind"), Expression.literal(9f), Expression.stop("driver", 11f))),
                PropertyFactory.circleColor(
                    Expression.match(
                        Expression.get("kind"), Expression.color(android.graphics.Color.parseColor("#1FA463")),
                        Expression.stop("dropoff", Expression.color(android.graphics.Color.parseColor("#D92D20"))),
                        Expression.stop("driver", Expression.color(brand)),
                    ),
                ),
                PropertyFactory.circleStrokeWidth(3f),
                PropertyFactory.circleStrokeColor(
                    Expression.match(Expression.get("kind"), Expression.color(android.graphics.Color.WHITE), Expression.stop("driver", Expression.color(android.graphics.Color.parseColor("#111111")))),
                ),
            ),
        )
        onReady(style)
    }
}

private fun update(style: Style, pickup: LatLng?, dropoff: LatLng?, driver: LatLng?) {
    val features = buildList {
        pickup?.let { add(feature(it, "pickup")) }
        dropoff?.let { add(feature(it, "dropoff")) }
        driver?.let { add(feature(it, "driver")) }
    }
    style.getSourceAs<GeoJsonSource>(SOURCE)?.setGeoJson(FeatureCollection.fromFeatures(features))
}

private fun feature(point: LatLng, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
